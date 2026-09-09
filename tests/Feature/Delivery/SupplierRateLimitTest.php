<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Actions\FulfilOrderItem;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierRateLimiter;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ops\Recovery\ResolveStuckDeliveries;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Gateways\PaymentGateway;
use App\Jobs\FulfilOrderItemJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

/**
 * Stage 2, task 3: a spike of orders against a supplier that accepts a limited
 * number of requests per minute.
 *
 * Nothing may be lost and the limit may not be exceeded, which means the spike
 * has to turn into a queue rather than into failures.
 */
class SupplierRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private FakeSupplierClient $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();

        $this->supplier = new FakeSupplierClient;
        $this->app->instance(SupplierClient::class, $this->supplier);
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
    }

    /**
     * Sets the allowance and pins the clock to the start of a window.
     *
     * Every assertion here counts requests inside one window, so a run that
     * happened to cross a minute boundary would see the counter reset and fail
     * for reasons that have nothing to do with the limiter. Pinning makes the
     * window a property of the scenario instead of of when the suite was started.
     */
    private function limitSuppliersTo(int $perMinute): void
    {
        config([
            'ggsell.suppliers.rate_limit.a' => $perMinute,
            'ggsell.suppliers.rate_limit.b' => $perMinute,
        ]);

        $this->travelTo(now()->startOfMinute());
    }

    private function paidOrder(array|string $skus): Order
    {
        return $this->makeOrder($skus, [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
        ]);
    }

    #[Test]
    public function the_window_hands_out_exactly_the_agreed_number_of_requests(): void
    {
        $this->limitSuppliersTo(3);
        $limiter = app(SupplierRateLimiter::class);

        $granted = 0;

        for ($i = 0; $i < 10; $i++) {
            $granted += $limiter->tryAcquire(SupplierId::A) ? 1 : 0;
        }

        $this->assertSame(3, $granted, 'The supplier must never be asked more than it agreed to.');
        $this->assertSame(0, $limiter->remaining(SupplierId::A));
        $this->assertFalse($limiter->tryAcquire(SupplierId::A));

        // Supplier B keeps its own allowance.
        $this->assertTrue($limiter->tryAcquire(SupplierId::B));
    }

    /**
     * The regression that made this a window instead of a token bucket.
     *
     * A bucket that starts full and refills continuously allows its capacity plus
     * a window of refill inside one of the supplier's windows. Thirty requests
     * followed by paced traffic then pushed a supplier limited to thirty per
     * minute past its limit, and it began answering 429.
     */
    #[Test]
    public function a_burst_followed_by_paced_traffic_stays_within_one_minute(): void
    {
        $this->limitSuppliersTo(10);
        $limiter = app(SupplierRateLimiter::class);

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($limiter->tryAcquire(SupplierId::A));
        }

        // Half a minute in there is still nothing left, however much time passed.
        $this->travel(30)->seconds();
        $this->assertFalse($limiter->tryAcquire(SupplierId::A));
        $this->assertSame(0, $limiter->remaining(SupplierId::A));

        // The next window starts fresh, exactly as the supplier's own counter does.
        $this->travel(31)->seconds();
        $this->assertSame(10, $limiter->remaining(SupplierId::A));
        $this->assertTrue($limiter->tryAcquire(SupplierId::A));
    }

    /**
     * The core requirement: over the limit, orders queue instead of failing.
     */
    /**
     * Drains a supplier's allowance, modelling one that is already saturated by
     * traffic this test is not about.
     */
    private function drainAllowance(SupplierId $supplier, int $requests): void
    {
        $limiter = app(SupplierRateLimiter::class);

        for ($i = 0; $i < $requests; $i++) {
            $limiter->tryAcquire($supplier);
        }
    }

    #[Test]
    public function a_spike_beyond_the_limit_is_queued_rather_than_lost(): void
    {
        $this->limitSuppliersTo(2);
        // Supplier B is already at its limit, so this scenario is about A alone.
        $this->drainAllowance(SupplierId::B, 2);
        Queue::fake();

        // Two lines can be served now; the third has to wait.
        $order = $this->paidOrder(['KEY-CS2-PRIME', 'KEY-GTA5', 'KEY-EFT']);

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('CODE-1', 200, 5, 'KEY-CS2-PRIME'),
            SupplierResponse::ok('CODE-2', 200, 5, 'KEY-GTA5'),
        ]);

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('position')->get();

        foreach ($items as $item) {
            (new FulfilOrderItemJob($item->id))->handle(app(FulfilOrderItem::class));
        }

        $this->assertDatabaseCount('deliveries', 2);

        // The line that found no allowance kept its state and came back on the
        // queue: no failure, no refund, nothing dropped.
        $third = $items[2]->refresh();
        $this->assertSame(OrderItemStatus::Pending, $third->status);
        $this->assertSame(0, $third->fulfilment_runs, 'Waiting for capacity must not spend the retry budget.');

        // Only the two permitted requests ever reached the supplier.
        $this->assertCount(2, $this->supplier->calls);
    }

    #[Test]
    public function a_queued_line_is_delivered_once_the_allowance_returns(): void
    {
        $this->limitSuppliersTo(1);
        $this->drainAllowance(SupplierId::B, 1);

        $order = $this->paidOrder(['KEY-CS2-PRIME', 'KEY-GTA5']);
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('position')->get();

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('CODE-FIRST', 200, 5, 'KEY-CS2-PRIME'),
            SupplierResponse::ok('CODE-SECOND', 200, 5, 'KEY-GTA5'),
        ]);

        app(FulfilOrderItem::class)->handle($items[0]->id);
        $this->assertFalse(
            app(FulfilOrderItem::class)->handle($items[1]->id),
            'With no allowance left the second line must report that it was deferred.',
        );

        // A minute later the window has rolled over.
        $this->travel(61)->seconds();

        $this->assertTrue(app(FulfilOrderItem::class)->handle($items[1]->id));

        $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);
        $this->assertDatabaseCount('deliveries', 2);
        $this->assertMoneyConserved();
    }

    /**
     * The supplier's own books: it never had to reject a request, because the
     * core never sent one it had not paid for out of its allowance.
     */
    #[Test]
    public function the_stub_rejects_only_traffic_the_core_would_never_send(): void
    {
        $this->limitSuppliersTo(3);
        $this->seedSupplierKeys('a', 'KEY-CS2-PRIME', 10);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/suppliers/a/issue', [
                'request_id' => "req_rate_a_{$i}",
                'sku' => 'KEY-CS2-PRIME',
                'order_id' => "ord_{$i}",
            ])->assertOk();
        }

        // The fourth request exceeds what the supplier agreed to serve.
        $this->postJson('/api/suppliers/a/issue', [
            'request_id' => 'req_rate_a_4',
            'sku' => 'KEY-CS2-PRIME',
            'order_id' => 'ord_4',
        ])
            ->assertStatus(429)
            ->assertJson(['status' => 'error', 'reason' => 'rate_limited'])
            ->assertHeader('Retry-After');

        // A rejected request costs no inventory.
        $this->assertSame(3, DB::table('stub.supplier_keys')->where('status', 'issued')->count());

        $this->getJson('/api/suppliers/a/rate')
            ->assertOk()
            ->assertJson(['supplier' => 'a', 'limit_per_minute' => 3, 'used' => 3, 'rejected' => 1]);
    }

    #[Test]
    public function paid_deliveries_and_background_recovery_use_separate_queues(): void
    {
        Queue::fake();

        // Two separate orders, because job uniqueness would otherwise collapse the
        // second dispatch for one line into the first.
        $fresh = $this->paidOrder('KEY-CS2-PRIME');
        $stuck = $this->paidOrder('KEY-GTA5');

        $stuckItem = $this->itemOf($stuck);
        $stuckItem->timestamps = false;
        $stuckItem->updated_at = now()->subHour();
        $stuckItem->save();

        app(FulfilOrder::class)->handle($fresh->id);
        $this->assertSame(1, app(ResolveStuckDeliveries::class)->handle(stuckAfterSeconds: 60));

        $queues = Queue::pushed(FulfilOrderItemJob::class)
            ->map(fn (FulfilOrderItemJob $job): ?string => $job->queue)
            ->all();

        $this->assertSame(
            [FulfilOrderItemJob::QUEUE_PAID, FulfilOrderItemJob::QUEUE_RECOVERY],
            $queues,
            'A paying customer is served from one queue and the system\'s own retries from another.',
        );
    }

    #[Test]
    public function progress_endpoint_reports_the_backlog_and_the_remaining_allowance(): void
    {
        $this->limitSuppliersTo(4);

        $order = $this->paidOrder(['KEY-CS2-PRIME', 'KEY-GTA5']);
        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('DONE-1', 200, 5, 'KEY-CS2-PRIME'),
            SupplierResponse::rejected('out_of_stock', 409, 5),
        ]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 5)]);

        app(FulfilOrder::class)->handle($order->id);

        $response = $this->getJson('/api/v1/ops/delivery/progress')->assertOk();

        $this->assertSame(1, $response->json('items.delivered'));
        $this->assertSame(1, $response->json('items.awaiting_delivery'));
        $this->assertSame(0, $response->json('items.refunded'));
        $this->assertSame(1, $response->json('orders.out_of_stock'));

        $allowance = collect($response->json('supplier_allowance'))->keyBy('supplier');
        $this->assertSame(4, $allowance['a']['per_minute']);
        // Supplier A served two requests, one code and one shortage answer, so two
        // of its four remain in this window.
        $this->assertSame(2, $allowance['a']['remaining']);
        // Supplier B was only asked about the line A could not fill.
        $this->assertSame(3, $allowance['b']['remaining']);

        $this->assertArrayHasKey(FulfilOrderItemJob::QUEUE_PAID, $response->json('queues'));
    }
}
