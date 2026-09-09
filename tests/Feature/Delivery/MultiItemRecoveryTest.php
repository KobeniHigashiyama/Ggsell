<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Actions\FulfilOrderItem;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ops\Recovery\ResolveStuckDeliveries;
use App\Domain\Ordering\Actions\SettleUnfulfillableItems;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Gateways\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

/**
 * Stage 2, task 1, point 5: an order reaches a terminal state even after the
 * process is killed in the middle of delivering it.
 *
 * The crash is modeled the way it actually happens: some lines are finished,
 * one is stopped between the supplier response and the delivery row, and the
 * rest never started. Recovery has to sort that out without a second key and
 * without a second refund.
 */
class MultiItemRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeSupplierClient $supplier;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();

        $this->supplier = new FakeSupplierClient;
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(SupplierClient::class, $this->supplier);
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    /** @return list<OrderItem> */
    private function itemsOf(Order $order): array
    {
        return OrderItem::query()->where('order_id', $order->id)->orderBy('position')->get()->all();
    }

    #[Test]
    public function order_killed_mid_delivery_reaches_a_terminal_state(): void
    {
        $order = $this->makeOrder(['KEY-CS2-PRIME', 'KEY-GTA5', 'KEY-EFT'], [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
        ]);

        [$first, $second, $third] = $this->itemsOf($order);

        // Line 1 finished normally before the crash.
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('CODE-LINE-1', 200, 6)]);
        app(FulfilOrderItem::class)->handle($first->id);

        // Line 2 stopped between the supplier response and the delivery row: the
        // code exists in the attempt, but nothing was committed.
        DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $second->id,
            'supplier' => SupplierId::A->value,
            'request_id' => 'req_'.$second->public_id.'_a_1',
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'CODE-BEFORE-CRASH',
            'reported_sku' => 'KEY-GTA5',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
        ]);
        $second->forceFill(['status' => OrderItemStatus::Delivering])->save();

        // Line 3 never started and its product is sold out everywhere.
        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('out_of_stock', 409, 8)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        $callsBeforeRecovery = count($this->supplier->calls);

        // Restart: background recovery replays the unfinished work.
        DB::table('order_items')->where('order_id', $order->id)->update(['updated_at' => now()->subHour()]);
        $this->assertSame(2, app(ResolveStuckDeliveries::class)->handle(stuckAfterSeconds: 60));

        [$first, $second, $third] = $this->itemsOf($order);
        $this->assertSame(OrderItemStatus::Delivered, $first->status);
        $this->assertSame(OrderItemStatus::Delivered, $second->status, 'The stored code must be committed, not reissued.');
        $this->assertSame(OrderItemStatus::OutOfStock, $third->status);

        // The recovered line used the code it already had.
        $this->assertSame('CODE-BEFORE-CRASH', DB::table('deliveries')
            ->where('order_item_id', $second->id)->value('code'));
        $this->assertSame(
            $callsBeforeRecovery + 2,
            count($this->supplier->calls),
            'Only the untouched line may reach a supplier, twice, once per supplier.',
        );

        // Settlement closes the line that can never be delivered.
        $this->assertSame(1, app(SettleUnfulfillableItems::class)->handle());

        $order->refresh();
        $this->assertSame(OrderStatus::PartiallyDelivered, $order->status);
        $this->assertTrue($order->status->isTerminal());
        $this->assertNotNull($order->settled_at);

        $this->assertDatabaseCount('deliveries', 2);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertMoneyConserved();
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function replaying_the_whole_order_after_settlement_changes_nothing(): void
    {
        $order = $this->makeOrder(['KEY-CS2-PRIME', 'KEY-GTA5'], [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
        ]);

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('CODE-ONE', 200, 6),
            SupplierResponse::rejected('out_of_stock', 409, 8),
        ]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        app(FulfilOrder::class)->handle($order->id);
        app(SettleUnfulfillableItems::class)->handle();

        $this->assertSame(OrderStatus::PartiallyDelivered, $order->refresh()->status);

        $supplierCalls = count($this->supplier->calls);
        $gatewayCalls = count($this->gateway->calls);

        // Every entry point replayed: the webhook path, recovery, and settlement.
        app(FulfilOrder::class)->handle($order->id);
        app(ResolveStuckDeliveries::class)->handle(stuckAfterSeconds: 0);
        app(SettleUnfulfillableItems::class)->handle();

        $this->assertCount($supplierCalls, $this->supplier->calls);
        $this->assertCount($gatewayCalls, $this->gateway->calls);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(OrderStatus::PartiallyDelivered, $order->refresh()->status);
        $this->assertMoneyConserved();
    }
}
