<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\FulfilOrderItem;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ops\Recovery\ResolveStuckDeliveries;
use App\Domain\Ordering\Actions\SettleUnfulfillableItems;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Actions\RefundOrderItem;
use App\Domain\Refunds\Gateways\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

/**
 * Mass delivery while a supplier is failing.
 *
 * The individual mechanisms have their own tests; this is the combination that
 * actually happens in production and that the two of them together do not cover:
 * many paid lines in flight at the moment a supplier stops behaving.
 *
 * The properties are the ones that cannot be recovered from if they break —
 * a code delivered twice, a key paid for and lost, money that stops adding up —
 * and they are asserted across the whole batch rather than one order at a time.
 */
class MassDeliveryUnderFailureTest extends TestCase
{
    use RefreshDatabase;

    private const BATCH = 12;

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
     * A batch of paid single-line orders, the shape a spike actually has.
     *
     * @return list<Order>
     */
    private function paidBatch(int $count = self::BATCH): array
    {
        $orders = [];

        for ($i = 0; $i < $count; $i++) {
            $orders[] = $this->makeOrder('KEY-CS2-PRIME', [
                'status' => OrderStatus::Paid,
                'paid_at' => now()->subMinutes(30),
            ]);
        }

        return $orders;
    }

    /** Runs delivery for every unsettled line, the way the queue would. */
    private function deliverEverything(): void
    {
        $ids = OrderItem::query()->unsettled()->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            app(FulfilOrderItem::class)->handle((int) $id);
        }
    }

    /**
     * The invariants that must hold for the whole batch, whatever happened.
     */
    private function assertBatchIsConsistent(int $expectedLines): void
    {
        $this->assertSame(
            0,
            OrderItem::query()->whereNull('settled_at')->count(),
            'Every line must end delivered or refunded.',
        );

        $this->assertSame(
            $expectedLines,
            OrderItem::query()->whereIn('status', [
                OrderItemStatus::Delivered->value,
                OrderItemStatus::Refunded->value,
            ])->count(),
        );

        $deliveries = DB::table('deliveries')->count();
        $this->assertSame(
            $deliveries,
            DB::table('deliveries')->distinct()->count('code'),
            'One code must never reach two customers.',
        );

        $this->assertSame(
            0,
            Order::query()->whereNotIn('status', [
                OrderStatus::Delivered->value,
                OrderStatus::PartiallyDelivered->value,
                OrderStatus::Refunded->value,
            ])->count(),
            'Every order must reach a terminal state.',
        );

        $this->assertMoneyConserved();
        $this->assertLedgerBalanced();
    }

    /**
     * A supplier that keeps taking keys and losing the answers, under load, for
     * every line at once. The retry inside the attempt reuses the request_id, so
     * the codes come back on the second ask.
     */
    #[Test]
    public function a_timing_out_supplier_delivers_every_line_exactly_once(): void
    {
        $this->paidBatch();
        $this->supplier->timesOutAfterIssuing(SupplierId::A);
        $this->supplier->script(SupplierId::B, array_fill(0, self::BATCH, SupplierResponse::ok('B-SHOULD-NOT-BE-USED', 200, 5, 'KEY-CS2-PRIME')));

        $this->deliverEverything();

        $this->assertSame(self::BATCH, DB::table('deliveries')->count());
        $this->assertSame(
            [SupplierId::A->value],
            array_values(array_unique(array_column($this->supplier->calls, 'supplier'))),
            'An unresolved outcome must never allow a fallback, however many lines are waiting.',
        );
        $this->assertNotContains(
            'B-SHOULD-NOT-BE-USED',
            DB::table('deliveries')->pluck('code')->all(),
        );

        // One attempt and one request_id per line: the timeout cost a retry, not
        // a second key.
        $this->assertSame(self::BATCH, DB::table('delivery_attempts')->count());
        $this->assertCount(self::BATCH, array_unique($this->supplier->requestIds()));

        $this->assertBatchIsConsistent(self::BATCH);
    }

    /**
     * The same failure, lasting long enough to exhaust the retries inside each
     * attempt, across a whole batch at once.
     *
     * Two things then happen together, and the interesting part is that they do
     * not interfere: lines already in flight are parked with an unresolved
     * outcome, and the circuit breaker takes the failing supplier out of the
     * chain so the rest of the batch is served by the healthy one. Through all of
     * it, no line may consume two keys.
     */
    #[Test]
    public function a_batch_parked_by_unresolved_timeouts_is_recovered_without_a_second_key(): void
    {
        $this->paidBatch();

        // Every attempt exhausts its retries without ever learning the outcome.
        $this->supplier->timesOutAfterIssuing(SupplierId::A, timeouts: (int) config('ggsell.suppliers.max_attempts'));
        $this->supplier->script(SupplierId::B, array_map(
            static fn (int $i): SupplierResponse => SupplierResponse::ok("B-CODE-{$i}", 200, 5, 'KEY-CS2-PRIME'),
            range(1, self::BATCH),
        ));

        $this->deliverEverything();

        $parked = OrderItem::query()->where('failure_reason', 'unresolved_attempt')->count();
        $servedByB = DB::table('deliveries')->where('supplier', 'b')->count();

        $this->assertGreaterThan(0, $parked, 'Lines caught mid-flight must be parked, not failed.');
        $this->assertGreaterThan(
            0,
            $servedByB,
            'Once the breaker opens, the rest of the batch must be served by the healthy supplier.',
        );

        // The invariant that survives both behaviors: one line, one key. A line
        // parked on an unknown outcome was never offered to the other supplier.
        $this->assertNoLineHasMoreThanOneAttempt();

        // Recovery reconciles the parked lines with the same supplier and the same
        // request_id, which is what turns a lost response into a delivery.
        DB::table('order_items')->update(['updated_at' => now()->subHour()]);
        app(ResolveStuckDeliveries::class)->handle(limit: 100, stuckAfterSeconds: 60);

        $this->assertSame(self::BATCH, DB::table('deliveries')->count());
        $this->assertNoLineHasMoreThanOneAttempt();
        $this->assertCount(
            self::BATCH,
            array_unique($this->supplier->requestIds()),
            'One request_id per line, across the timeouts and the reconciliation.',
        );

        $this->assertBatchIsConsistent(self::BATCH);
    }

    /**
     * One line, one supplier request stream, one key.
     *
     * A second attempt for a line whose first outcome was never resolved is the
     * exact shape of buying two keys for one payment, so it is asserted directly
     * rather than inferred from the totals.
     */
    private function assertNoLineHasMoreThanOneAttempt(): void
    {
        $withSeveral = DB::table('delivery_attempts')
            ->select('order_item_id')
            ->groupBy('order_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_item_id')
            ->all();

        $this->assertSame([], $withSeveral, 'A line must never open a second supplier attempt while the first is unresolved.');
    }

    #[Test]
    public function a_supplier_that_fails_outright_mid_batch_shifts_the_rest_to_the_other_one(): void
    {
        $this->paidBatch();

        // Supplier A serves the first four lines and then falls over for good.
        $this->supplier->script(SupplierId::A, [
            ...array_map(
                static fn (int $i): SupplierResponse => SupplierResponse::ok("A-CODE-{$i}", 200, 5, 'KEY-CS2-PRIME'),
                range(1, 4),
            ),
            ...array_fill(0, self::BATCH - 4, SupplierResponse::rejected('supplier_error', 503, 20)),
        ]);
        $this->supplier->script(SupplierId::B, array_map(
            static fn (int $i): SupplierResponse => SupplierResponse::ok("B-CODE-{$i}", 200, 5, 'KEY-CS2-PRIME'),
            range(1, self::BATCH - 4),
        ));

        $this->deliverEverything();

        $this->assertSame(self::BATCH, DB::table('deliveries')->count());
        $this->assertSame(4, DB::table('deliveries')->where('supplier', 'a')->count());
        $this->assertSame(self::BATCH - 4, DB::table('deliveries')->where('supplier', 'b')->count());

        $this->assertBatchIsConsistent(self::BATCH);
    }

    /**
     * The supply side is gone entirely: the batch has to end in refunds rather
     * than in orders nobody ever closes.
     */
    #[Test]
    public function a_batch_with_no_supplier_left_is_refunded_in_full(): void
    {
        $orders = $this->paidBatch();

        foreach ([SupplierId::A, SupplierId::B] as $supplier) {
            $this->supplier->script($supplier, array_fill(
                0,
                self::BATCH,
                SupplierResponse::rejected('out_of_stock', 409, 8),
            ));
        }

        $this->deliverEverything();
        $this->assertSame(self::BATCH, app(SettleUnfulfillableItems::class)->handle(limit: 100));

        $this->assertSame(0, DB::table('deliveries')->count());
        $this->assertSame(self::BATCH, DB::table('refunds')->count());
        $this->assertBatchIsConsistent(self::BATCH);

        foreach ($orders as $order) {
            $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
        }

        // One refund per line and no more, however many lines failed together.
        $this->assertCount(self::BATCH, $this->gateway->calls);
        $this->assertCount(self::BATCH, array_unique($this->gateway->requestIds()));
    }

    /**
     * Half the batch delivered before the outage, half after: the two halves must
     * not interfere, and the money has to land on both sides of the line.
     */
    #[Test]
    public function a_partly_served_batch_settles_both_halves_correctly(): void
    {
        $orders = $this->paidBatch();
        $half = intdiv(self::BATCH, 2);

        $this->supplier->script(SupplierId::A, [
            ...array_map(
                static fn (int $i): SupplierResponse => SupplierResponse::ok("SERVED-{$i}", 200, 5, 'KEY-CS2-PRIME'),
                range(1, $half),
            ),
            ...array_fill(0, self::BATCH - $half, SupplierResponse::rejected('out_of_stock', 409, 8)),
        ]);
        $this->supplier->script(SupplierId::B, array_fill(
            0,
            self::BATCH - $half,
            SupplierResponse::rejected('out_of_stock', 409, 8),
        ));

        $this->deliverEverything();
        app(SettleUnfulfillableItems::class)->handle(limit: 100);

        $this->assertSame($half, DB::table('deliveries')->count());
        $this->assertSame(self::BATCH - $half, DB::table('refunds')->count());
        $this->assertBatchIsConsistent(self::BATCH);

        $delivered = 0;
        $refunded = 0;

        foreach ($orders as $order) {
            $order->refresh()->status === OrderStatus::Delivered ? $delivered++ : $refunded++;
        }

        $this->assertSame($half, $delivered);
        $this->assertSame(self::BATCH - $half, $refunded);
    }

    /**
     * Replaying the whole batch through every entry point after it settled must
     * not buy a single extra key or move a single extra rouble.
     */
    #[Test]
    public function replaying_a_settled_batch_changes_nothing(): void
    {
        $this->paidBatch();
        $this->supplier->timesOutAfterIssuing(SupplierId::A);

        $this->deliverEverything();
        DB::table('order_items')->update(['updated_at' => now()->subHour()]);
        app(ResolveStuckDeliveries::class)->handle(limit: 100, stuckAfterSeconds: 60);

        $supplierCalls = count($this->supplier->calls);
        $deliveries = DB::table('deliveries')->count();
        $ledgerRows = DB::table('ledger_entries')->count();

        $this->deliverEverything();
        app(ResolveStuckDeliveries::class)->handle(limit: 100, stuckAfterSeconds: 0);
        app(SettleUnfulfillableItems::class)->handle(limit: 100);

        foreach (OrderItem::query()->pluck('id') as $id) {
            $this->assertNull(app(RefundOrderItem::class)->handle((int) $id));
        }

        $this->assertCount($supplierCalls, $this->supplier->calls);
        $this->assertSame($deliveries, DB::table('deliveries')->count());
        $this->assertSame($ledgerRows, DB::table('ledger_entries')->count());
        $this->assertSame([], $this->gateway->calls);
        $this->assertBatchIsConsistent(self::BATCH);
    }
}
