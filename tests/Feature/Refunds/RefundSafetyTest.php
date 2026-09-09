<?php

declare(strict_types=1);

namespace Tests\Feature\Refunds;

use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Actions\SettleUnfulfillableItems;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Actions\RefundOrderItem;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Gateways\PaymentGateway;
use App\Domain\Refunds\Gateways\RefundResponse;
use App\Domain\Refunds\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

/**
 * Stage 2, task 1, points 4 and 5: any step may be repeated, and an order still
 * reaches a terminal state after a crash in the middle of settlement.
 */
class RefundSafetyTest extends TestCase
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

    /** A paid order whose only line is out of stock at every supplier. */
    private function undeliverableOrder(): Order
    {
        $order = $this->makeOrder('KEY-GTA5', [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
        ]);

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('out_of_stock', 409, 8)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        app(FulfilOrder::class)->handle($order->id);

        return $order;
    }

    private function refundLedgerEntries(): int
    {
        return DB::table('ledger_entries')->where('ref_type', 'refund_item')->count();
    }

    #[Test]
    public function refund_timeout_followed_by_a_retry_returns_the_money_once(): void
    {
        $order = $this->undeliverableOrder();
        $item = $this->itemOf($order);

        // Every try times out: the gateway may or may not have moved the money.
        $this->gateway->script(array_fill(0, 3, RefundResponse::unknown('timeout', null, 2000)));

        $this->assertSame(RefundStatus::Unknown, app(RefundOrderItem::class)->handle($item->id));

        // An unknown outcome must not be recognized as a refund.
        $this->assertSame(OrderItemStatus::OutOfStock, $item->refresh()->status);
        $this->assertNull($item->settled_at);
        $this->assertSame(0, $this->refundLedgerEntries());
        $this->assertSame(OrderStatus::OutOfStock, $order->refresh()->status);

        // It turns out the money did leave on that first call.
        $this->gateway->alreadyRefunded(Refund::makeRequestId($item), 'rfd_from_lost_response');

        $this->assertSame(RefundStatus::Succeeded, app(RefundOrderItem::class)->handle($item->id));

        $this->assertSame(OrderItemStatus::Refunded, $item->refresh()->status);
        $this->assertSame('rfd_from_lost_response', Refund::query()->sole()->gateway_reference);

        // One refund, one pair of ledger entries, no matter how many calls.
        $this->assertSame(2, $this->refundLedgerEntries());
        $this->assertDatabaseCount('refunds', 1);
        $this->assertMoneyConserved();
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function repeating_a_completed_refund_changes_nothing(): void
    {
        $order = $this->undeliverableOrder();
        $item = $this->itemOf($order);

        app(RefundOrderItem::class)->handle($item->id);
        $callsAfterFirst = count($this->gateway->calls);

        $this->assertNull(app(RefundOrderItem::class)->handle($item->id));
        $this->assertNull(app(RefundOrderItem::class)->handle($item->id));

        $this->assertCount($callsAfterFirst, $this->gateway->calls);
        $this->assertSame(2, $this->refundLedgerEntries());
        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
        $this->assertMoneyConserved();
    }

    /**
     * A crash between the gateway confirming and the bookkeeping being written
     * leaves a succeeded refund attached to an unsettled item. Recovery must
     * finish the bookkeeping instead of asking for the money again.
     */
    #[Test]
    public function crash_after_the_gateway_confirmed_is_repaired_without_a_second_refund(): void
    {
        $order = $this->undeliverableOrder();
        $item = $this->itemOf($order);

        Refund::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'refund_request_id' => Refund::makeRequestId($item),
            'amount_minor' => $item->amount_minor,
            'currency' => $item->currency,
            'status' => RefundStatus::Succeeded,
            'reason' => 'out_of_stock',
            'gateway_reference' => 'rfd_before_crash',
            'requested_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);

        app(RefundOrderItem::class)->handle($item->id);

        $this->assertSame([], $this->gateway->calls, 'A confirmed refund must not be requested again.');
        $this->assertSame(OrderItemStatus::Refunded, $item->refresh()->status);
        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
        $this->assertSame(2, $this->refundLedgerEntries());
        $this->assertMoneyConserved();
    }

    #[Test]
    public function settlement_finishes_an_order_left_mid_refund(): void
    {
        $order = $this->undeliverableOrder();
        $item = $this->itemOf($order);

        // The process died right after the gateway call, outcome unknown.
        Refund::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'refund_request_id' => Refund::makeRequestId($item),
            'amount_minor' => $item->amount_minor,
            'currency' => $item->currency,
            'status' => RefundStatus::Unknown,
            'reason' => 'out_of_stock',
            'requested_at' => now()->subMinute(),
            'tries' => 1,
        ]);

        // The scheduled sweep picks the line up again and asks under the same id.
        $this->assertSame(1, app(SettleUnfulfillableItems::class)->handle());

        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
        $this->assertNotNull($order->settled_at);
        $this->assertSame([Refund::makeRequestId($item)], $this->gateway->requestIds());
        $this->assertMoneyConserved();
    }

    /**
     * The core hazard of a partially failed order: a supplier that may still owe
     * a code must block the refund, or the customer could receive both.
     */
    #[Test]
    public function refund_is_blocked_while_a_supplier_attempt_is_unresolved(): void
    {
        $order = $this->makeOrder('KEY-GTA5', [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
            'item_status' => OrderItemStatus::DeliveryFailed,
        ]);
        $item = $this->itemOf($order);

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'supplier' => SupplierId::A->value,
            'request_id' => 'req_'.$item->public_id.'_a_1',
            'attempt_no' => 1,
            'tries' => 2,
            'status' => AttemptStatus::Unknown,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->assertNull(app(RefundOrderItem::class)->handle($item->id));
        $this->assertSame(0, app(SettleUnfulfillableItems::class)->handle());

        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('refunds', 0);
    }

    #[Test]
    public function refund_is_blocked_when_a_code_was_issued_but_not_committed(): void
    {
        $order = $this->makeOrder('KEY-GTA5', [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
            'item_status' => OrderItemStatus::DeliveryFailed,
        ]);
        $item = $this->itemOf($order);

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'supplier' => SupplierId::A->value,
            'request_id' => 'req_'.$item->public_id.'_a_1',
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'ISSUED-NOT-COMMITTED',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
        ]);

        $this->assertNull(app(RefundOrderItem::class)->handle($item->id));
        $this->assertSame(0, app(SettleUnfulfillableItems::class)->handle());
        $this->assertDatabaseCount('refunds', 0);
    }

    /**
     * The mirror of the check above: once money is on its way back, delivery is
     * off the table even if stock reappears.
     */
    #[Test]
    public function delivery_is_refused_once_a_refund_is_in_flight(): void
    {
        $order = $this->undeliverableOrder();
        $item = $this->itemOf($order);

        Refund::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'refund_request_id' => Refund::makeRequestId($item),
            'amount_minor' => $item->amount_minor,
            'currency' => $item->currency,
            'status' => RefundStatus::Unknown,
            'reason' => 'out_of_stock',
            'requested_at' => now(),
            'tries' => 1,
        ]);

        $callsBefore = count($this->supplier->calls);
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('RESTOCKED-CODE', 200, 5)]);

        app(FulfilOrder::class)->handle($order->id);

        $this->assertCount($callsBefore, $this->supplier->calls, 'No supplier may be contacted for a refunding line.');
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertSame(OrderItemStatus::OutOfStock, OrderItem::query()->findOrFail($item->id)->status);
    }
}
