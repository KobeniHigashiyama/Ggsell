<?php

declare(strict_types=1);

namespace Tests\Feature\Refunds;

use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Actions\SettleUnfulfillableItems;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Gateways\PaymentGateway;
use App\Domain\Refunds\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

/**
 * Stage 2, task 1: an order of several products where part cannot be delivered.
 *
 * What was delivered stays with the customer, the rest is refunded, and the
 * money adds up afterwards.
 */
class PartialOrderSettlementTest extends TestCase
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

    /**
     * An order paid half an hour ago.
     *
     * Settlement decides by how long the customer has waited, so the age is part
     * of the scenario rather than something the wall clock should supply.
     */
    private function paidOrder(array $skus): Order
    {
        return $this->makeOrder($skus, [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
        ]);
    }

    /** @return array<string, OrderItem> Items keyed by SKU. */
    private function itemsBySku(Order $order): array
    {
        return OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('position')
            ->get()
            ->keyBy('sku')
            ->all();
    }

    #[Test]
    public function delivered_lines_stay_and_undeliverable_lines_are_refunded(): void
    {
        $order = $this->paidOrder(['KEY-CS2-PRIME', 'KEY-GTA5']);

        // The first line finds a key; the second is out of stock everywhere.
        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('CODE-FOR-LINE-1', 200, 8),
            SupplierResponse::rejected('out_of_stock', 409, 8),
        ]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        app(FulfilOrder::class)->handle($order->id);

        $items = $this->itemsBySku($order);
        $this->assertSame(OrderItemStatus::Delivered, $items['KEY-CS2-PRIME']->status);
        $this->assertSame(OrderItemStatus::OutOfStock, $items['KEY-GTA5']->status);

        // While a line may still be retried the order is not finished yet.
        $this->assertSame(OrderStatus::OutOfStock, $order->refresh()->status);
        $this->assertNull($order->settled_at);

        // The customer has waited past the give-up window, so settlement returns
        // the money for the line that never arrived.
        $this->assertSame(1, app(SettleUnfulfillableItems::class)->handle());

        $items = $this->itemsBySku($order);
        $this->assertSame(OrderItemStatus::Delivered, $items['KEY-CS2-PRIME']->status);
        $this->assertSame(OrderItemStatus::Refunded, $items['KEY-GTA5']->status);
        $this->assertSame('CODE-FOR-LINE-1', $this->deliveredCode($order));

        $order->refresh();
        $this->assertSame(OrderStatus::PartiallyDelivered, $order->status);
        $this->assertTrue($order->status->isTerminal());
        $this->assertNotNull($order->settled_at);

        $this->assertMoneyConserved();
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function an_order_with_nothing_deliverable_is_fully_refunded(): void
    {
        $order = $this->paidOrder(['KEY-CS2-PRIME', 'KEY-GTA5']);

        $this->supplier->script(SupplierId::A, array_fill(0, 2, SupplierResponse::rejected('out_of_stock', 409, 8)));
        $this->supplier->script(SupplierId::B, array_fill(0, 2, SupplierResponse::rejected('out_of_stock', 409, 8)));

        app(FulfilOrder::class)->handle($order->id);
        $this->assertSame(2, app(SettleUnfulfillableItems::class)->handle());

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertNull($order->delivered_at);
        $this->assertNotNull($order->settled_at);
        $this->assertDatabaseCount('deliveries', 0);

        $this->assertMoneyConserved();
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function refund_is_recorded_against_the_gateway_exactly_once(): void
    {
        $order = $this->paidOrder(['KEY-GTA5']);

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('out_of_stock', 409, 8)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        app(FulfilOrder::class)->handle($order->id);

        // Two settlement sweeps in a row: the second must find nothing to do.
        app(SettleUnfulfillableItems::class)->handle();
        $this->assertSame(0, app(SettleUnfulfillableItems::class)->handle());

        $this->assertCount(1, $this->gateway->calls);
        $this->assertDatabaseCount('refunds', 1);

        $refund = Refund::query()->sole();
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame('rfn_'.$this->itemOf($order)->public_id, $refund->refund_request_id);
        $this->assertSame(199000, $refund->amount_minor);
        $this->assertSame('out_of_stock', $refund->reason);

        // One refund means one pair of ledger entries.
        $this->assertSame(2, DB::table('ledger_entries')
            ->where('ref_type', 'refund_item')->count());

        $this->assertMoneyConserved();
    }

    #[Test]
    public function delivered_line_is_never_refunded(): void
    {
        $order = $this->paidOrder(['KEY-GTA5']);
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('DELIVERED-CODE', 200, 6)]);

        app(FulfilOrder::class)->handle($order->id);
        $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);

        $this->assertSame(0, app(SettleUnfulfillableItems::class)->handle());
        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertMoneyConserved();
    }
}
