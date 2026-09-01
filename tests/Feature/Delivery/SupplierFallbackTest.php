<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\CommitDelivery;
use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\IllegalTransition;
use App\Domain\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

class SupplierFallbackTest extends TestCase
{
    use RefreshDatabase;

    private FakeSupplierClient $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();

        $this->supplier = new FakeSupplierClient;
        $this->app->instance(SupplierClient::class, $this->supplier);
    }

    private function paidOrder(): Order
    {
        return Order::create([
            'public_id' => Order::newPublicId(),
            'sku' => 'KEY-CS2-PRIME',
            'quantity' => 1,
            'amount_minor' => 129000,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    #[Test]
    public function при_определённом_отказе_поставщика_a_товар_выдаёт_b_ровно_один_раз(): void
    {
        $order = $this->paidOrder();

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('supplier_error', 503, 30)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('CODE-FROM-B', 200, 12)]);

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('CODE-FROM-B', $order->delivery->code);
        $this->assertSame(SupplierId::B, $order->delivery->supplier);
        $this->assertDatabaseCount('deliveries', 1);

        $attempts = DeliveryAttempt::query()->orderBy('id')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame(AttemptStatus::Failed, $attempts[0]->status);
        $this->assertSame(AttemptStatus::Succeeded, $attempts[1]->status);
        $this->assertNotSame($attempts[0]->request_id, $attempts[1]->request_id);

        $this->assertLedgerBalanced();
    }

    #[Test]
    public function пустой_остаток_даёт_восстановимое_состояние_а_не_падение(): void
    {
        $order = $this->paidOrder();

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('out_of_stock', 409, 8)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::OutOfStock, $order->status);
        $this->assertTrue($order->status->isRecoverable());
        $this->assertDatabaseCount('deliveries', 0);

        // Payment without delivery must remain a liability after delivery fails.
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function после_пополнения_остатка_заказ_доводится_до_выдачи_без_задвоения(): void
    {
        $order = $this->paidOrder();

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('out_of_stock', 409, 8)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        app(FulfilOrder::class)->handle($order->id);
        $this->assertSame(OrderStatus::OutOfStock, $order->refresh()->status);

        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('RESTOCKED-0001', 200, 9)]);

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('RESTOCKED-0001', $order->delivery->code);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function повторный_прогон_по_выданному_заказу_ничего_не_делает(): void
    {
        $order = $this->paidOrder();
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('CODE-ONCE', 200, 7)]);

        app(FulfilOrder::class)->handle($order->id);
        $callsAfterFirst = count($this->supplier->calls);

        app(FulfilOrder::class)->handle($order->id);
        app(FulfilOrder::class)->handle($order->id);

        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertCount($callsAfterFirst, $this->supplier->calls, 'Выданный заказ не должен обращаться к поставщику.');
        $this->assertLedgerBalanced();
    }

    /**
     * Regression: a committed delivery must transition the order to delivered.
     *
     * A concurrent run may report a shortage after another receives a code. Once
     * deliveries contains a row, the status must reflect that the customer owns
     * the product and allow the API to expose its code.
     */
    #[Test]
    public function фиксация_выдачи_доводит_до_delivered_даже_из_out_of_stock(): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['status' => OrderStatus::OutOfStock, 'failure_reason' => 'out_of_stock'])->save();

        $attempt = DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => SupplierId::A->value,
            'request_id' => "req_{$order->public_id}_a_1",
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'LATE-CODE',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        $this->assertTrue(app(CommitDelivery::class)->handle($order, $attempt, 'LATE-CODE'));

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNull($order->failure_reason);
        $this->assertSame('LATE-CODE', $order->delivery->code);
    }

    /**
     * Regression: delivery for an unpaid order is transactionally impossible.
     *
     * A failed transition must roll back both the delivery row and revenue entry.
     */
    #[Test]
    public function фиксация_выдачи_по_неоплаченному_заказу_откатывается(): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['status' => OrderStatus::Created, 'paid_at' => null])->save();

        $attempt = DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => SupplierId::A->value,
            'request_id' => "req_{$order->public_id}_a_1",
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'SHOULD-NOT-LAND',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->expectException(IllegalTransition::class);

        try {
            app(CommitDelivery::class)->handle($order, $attempt, 'SHOULD-NOT-LAND');
        } finally {
            $this->assertDatabaseCount('deliveries', 0);
            $this->assertDatabaseCount('ledger_entries', 0);
        }
    }

    /**
     * Regression: a second run cannot contact a supplier while an unresolved
     * attempt exists, or one paid order could consume two keys.
     */
    #[Test]
    public function параллельный_прогон_не_открывает_вторую_попытку(): void
    {
        $order = $this->paidOrder();

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => SupplierId::A->value,
            'request_id' => "req_{$order->public_id}_a_1",
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Pending,
            'started_at' => now(),
        ]);

        // Reconciliation remains unknown, so the run must stop without a new request.
        $this->supplier->script(SupplierId::A, [SupplierResponse::unknown('timeout', null, 2000)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('SECOND-KEY', 200, 10)]);

        app(FulfilOrder::class)->handle($order->id);

        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertSame(
            [SupplierId::A->value],
            array_unique(array_column($this->supplier->calls, 'supplier')),
        );
    }
}
