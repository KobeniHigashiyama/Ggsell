<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\CommitDelivery;
use App\Domain\Delivery\Actions\DeliveryCommitResult;
use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Models\Delivery;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Enums\OrderItemStatus;
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
        return $this->makeOrder('KEY-CS2-PRIME', [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    /** The request_id the orchestrator will build for the first attempt. */
    private function firstRequestId(Order $order): string
    {
        return sprintf('req_%s_a_1', $this->itemOf($order)->public_id);
    }

    #[Test]
    public function supplier_b_delivers_exactly_once_after_supplier_a_definitively_rejects(): void
    {
        $order = $this->paidOrder();

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('supplier_error', 503, 30)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('CODE-FROM-B', 200, 12)]);

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('CODE-FROM-B', $this->deliveredCode($order));
        $this->assertSame(SupplierId::B, Delivery::query()->sole()->supplier);
        $this->assertDatabaseCount('deliveries', 1);

        $attempts = DeliveryAttempt::query()->orderBy('id')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame(AttemptStatus::Failed, $attempts[0]->status);
        $this->assertSame(AttemptStatus::Succeeded, $attempts[1]->status);
        $this->assertNotSame($attempts[0]->request_id, $attempts[1]->request_id);

        $this->assertLedgerBalanced();
    }

    #[Test]
    public function empty_stock_produces_recoverable_state_instead_of_failure(): void
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
    public function replenished_stock_allows_delivery_without_duplication(): void
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
        $this->assertSame('RESTOCKED-0001', $this->deliveredCode($order));
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function repeated_run_for_delivered_order_does_nothing(): void
    {
        $order = $this->paidOrder();
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('CODE-ONCE', 200, 7)]);

        app(FulfilOrder::class)->handle($order->id);
        $callsAfterFirst = count($this->supplier->calls);

        app(FulfilOrder::class)->handle($order->id);
        app(FulfilOrder::class)->handle($order->id);

        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertCount($callsAfterFirst, $this->supplier->calls, 'A delivered order must not contact a supplier.');
        $this->assertLedgerBalanced();
    }

    /**
     * Regression: a committed delivery must transition the item to delivered.
     *
     * A concurrent run may report a shortage after another receives a code. Once
     * deliveries contains a row, the status must reflect that the customer owns
     * the product and allow the API to expose its code.
     */
    #[Test]
    public function committing_delivery_reaches_delivered_even_from_out_of_stock(): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['status' => OrderStatus::OutOfStock, 'failure_reason' => 'out_of_stock'])->save();
        $this->itemOf($order)->forceFill([
            'status' => OrderItemStatus::OutOfStock,
            'failure_reason' => 'out_of_stock',
        ])->save();

        $attempt = DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $this->itemOf($order)->id,
            'supplier' => SupplierId::A->value,
            'request_id' => $this->firstRequestId($order),
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'LATE-CODE',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        $this->assertSame(
            DeliveryCommitResult::Committed,
            app(CommitDelivery::class)->handle($this->itemOf($order), $attempt, 'LATE-CODE'),
        );

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNull($order->failure_reason);
        $this->assertSame('LATE-CODE', $this->deliveredCode($order));
    }

    /**
     * Regression: delivery for an unpaid order is transactionally impossible.
     *
     * A failed transition must roll back both the delivery row and revenue entry.
     */
    #[Test]
    public function committing_delivery_for_unpaid_order_is_rolled_back(): void
    {
        // An unpaid order from the start: forcing a paid one back would leave its
        // payment entries behind and hide what this test is about.
        $order = $this->makeOrder('KEY-CS2-PRIME');

        $attempt = DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $this->itemOf($order)->id,
            'supplier' => SupplierId::A->value,
            'request_id' => $this->firstRequestId($order),
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
            app(CommitDelivery::class)->handle($this->itemOf($order), $attempt, 'SHOULD-NOT-LAND');
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
    public function concurrent_run_does_not_open_second_attempt(): void
    {
        $order = $this->paidOrder();

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $this->itemOf($order)->id,
            'supplier' => SupplierId::A->value,
            'request_id' => $this->firstRequestId($order),
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
