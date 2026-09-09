<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Catalog\Actions\SyncStockFlag;
use App\Domain\Catalog\Models\Product;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ops\Reconciliation\ReconciliationReport;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function seedCatalog(): void
    {
        $this->seed(CatalogSeeder::class);
    }

    /**
     * Seeds a specific supplier with an exact number of keys.
     *
     * Tests control stock explicitly because empty-stock scenarios require exact
     * per-supplier inventory.
     */
    protected function seedSupplierKeys(string $supplier, string $sku, int $count, string $prefix = 'TEST'): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'supplier' => $supplier,
                'sku' => $sku,
                'code' => sprintf('%s-%s-%s-%04d', $prefix, strtoupper($supplier), substr(md5($sku), 0, 4), $i),
                'status' => 'available',
            ];
        }

        if ($rows !== []) {
            DB::table('stub.supplier_keys')->insert($rows);
        }

        DB::table('product_stock')->where('sku', $sku)->update([
            'available_count' => DB::raw('available_count + '.$count),
        ]);

        app(SyncStockFlag::class)->handle($sku);
    }

    /**
     * Creates an order holding one item per listed SKU.
     *
     * Tests describe an order by what it contains and what state it is in; the
     * item rows, positions, and totals follow from that. Item states default to
     * the ones implied by the order status, which is what a real order in that
     * status looks like.
     *
     * @param  list<string>|string  $skus
     * @param  array<string, mixed>  $attributes  Order columns, plus item_status to override item state.
     */
    protected function makeOrder(array|string $skus, array $attributes = []): Order
    {
        $skus = (array) $skus;
        $products = Product::query()->whereIn('sku', $skus)->get()->keyBy('sku');
        $status = $attributes['status'] ?? OrderStatus::Created;
        $itemStatus = $attributes['item_status'] ?? self::itemStatusFor($status);
        // Lets a test pin an amount that does not match the catalog price while
        // keeping the order total equal to the sum of its lines.
        $itemAmount = $attributes['item_amount_minor'] ?? null;
        unset($attributes['item_status'], $attributes['item_amount_minor']);

        $order = Order::create($attributes + [
            'public_id' => Order::newPublicId(),
            'amount_minor' => array_sum(array_map(
                static fn (string $sku): int => $itemAmount ?? (int) $products[$sku]->price_minor,
                $skus,
            )),
            'currency' => $products[$skus[0]]->currency,
            'status' => $status,
        ]);

        foreach ($skus as $position => $sku) {
            OrderItem::create([
                'order_id' => $order->id,
                'public_id' => OrderItem::newPublicId(),
                'position' => $position + 1,
                'sku' => $sku,
                'amount_minor' => $itemAmount ?? $products[$sku]->price_minor,
                'currency' => $products[$sku]->currency,
                'status' => $itemStatus,
                'delivered_at' => $itemStatus === OrderItemStatus::Delivered ? now() : null,
                'settled_at' => $itemStatus === OrderItemStatus::Delivered ? now() : null,
            ]);
        }

        if ($order->paid_at !== null) {
            $this->recordPaymentFor($order);
        }

        return $order;
    }

    /**
     * Posts the ledger entries a real payment would have written.
     *
     * A paid order without them is not a state the system can produce, and tests
     * built on one would silently disagree with the money checks.
     */
    protected function recordPaymentFor(Order $order): void
    {
        app(PostTransaction::class)->handle(
            lines: [
                LedgerLine::debit(Account::Cash, $order->amount_minor),
                LedgerLine::credit(Account::CustomerLiability, $order->amount_minor),
            ],
            currency: $order->currency,
            refType: 'payment_event',
            refId: 'evt_seed_'.$order->public_id,
            orderId: $order->id,
        );
    }

    /** The item state a real order in this status would hold. */
    private static function itemStatusFor(OrderStatus $status): OrderItemStatus
    {
        return match ($status) {
            OrderStatus::Delivering => OrderItemStatus::Delivering,
            OrderStatus::Delivered => OrderItemStatus::Delivered,
            OrderStatus::OutOfStock => OrderItemStatus::OutOfStock,
            OrderStatus::DeliveryFailed => OrderItemStatus::DeliveryFailed,
            OrderStatus::Refunded => OrderItemStatus::Refunded,
            default => OrderItemStatus::Pending,
        };
    }

    /** The single item of a single-line order. */
    protected function itemOf(Order $order): OrderItem
    {
        return OrderItem::query()->where('order_id', $order->id)->orderBy('position')->sole();
    }

    /**
     * The code delivered for a single-line order.
     *
     * Read through the query builder rather than the relation: models fetched in
     * assertions are not the ones the action created, and lazy loading is
     * disabled in tests on purpose.
     */
    protected function deliveredCode(Order $order): ?string
    {
        return DB::table('deliveries')->where('order_id', $order->id)->value('code');
    }

    /**
     * Asserts the stage-2 money identity through the reconciliation report.
     *
     * Reading it from the report rather than recomputing it in the test means the
     * assertion and the operational check can never drift apart.
     */
    protected function assertMoneyConserved(): void
    {
        $check = app(ReconciliationReport::class)->build()['checks']['money_conservation'];

        $this->assertSame(
            0,
            $check['count'],
            'Money does not add up: '.json_encode($check['by_currency'], JSON_UNESCAPED_UNICODE),
        );
    }

    protected function assertLedgerBalanced(): void
    {
        $unbalanced = DB::table('ledger_entries')
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->havingRaw('SUM(amount_minor) <> 0')
            ->count();

        $this->assertSame(0, $unbalanced, 'The ledger is unbalanced.');
    }
}
