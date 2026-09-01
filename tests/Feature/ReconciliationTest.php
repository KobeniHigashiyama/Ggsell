<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Models\OrphanedCode;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ops\Reconciliation\ReconciliationReport;
use App\Domain\Ops\Recovery\ResolveStuckOrders;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payments\Actions\ReplayPendingEvents;
use App\Domain\Payments\Models\PaymentEvent;
use App\Jobs\FulfilOrderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

class ReconciliationTest extends TestCase
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

    /**
     * @param  int|null  $paidSecondsAgo  Null when the order is unpaid.
     */
    private function order(OrderStatus $status, ?int $paidSecondsAgo = 0): Order
    {
        return Order::create([
            'public_id' => Order::newPublicId(),
            'sku' => 'KEY-CS2-PRIME',
            'quantity' => 1,
            'amount_minor' => 129000,
            'currency' => 'RUB',
            'status' => $status,
            'paid_at' => $paidSecondsAgo !== null ? now()->subSeconds($paidSecondsAgo) : null,
        ]);
    }

    #[Test]
    public function clean_system_has_no_discrepancies(): void
    {
        $report = app(ReconciliationReport::class);
        $result = $report->build();

        $this->assertTrue($report->isHealthy($result), json_encode($result['checks'], JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function paid_but_undelivered_order_appears_in_reconciliation(): void
    {
        // The order was paid an hour ago and remains undelivered. paid_at keeps
        // failed delivery retries from hiding its age by updating updated_at.
        $this->order(OrderStatus::OutOfStock, paidSecondsAgo: 3600);

        $report = app(ReconciliationReport::class);
        $result = $report->build(60);

        $this->assertSame(1, $result['checks']['paid_not_delivered']['count']);
        $this->assertSame(129000, $result['checks']['paid_not_delivered']['amount_minor']);
        $this->assertFalse($report->isHealthy($result));
    }

    #[Test]
    public function liability_balance_matches_undelivered_order_total(): void
    {
        $order = $this->order(OrderStatus::Paid);

        app(PostTransaction::class)->handle(
            lines: [
                LedgerLine::debit(Account::Cash, 129000),
                LedgerLine::credit(Account::CustomerLiability, 129000),
            ],
            currency: 'RUB',
            refType: 'payment_event',
            refId: 'evt_recon',
            orderId: $order->id,
        );

        $result = app(ReconciliationReport::class)->build();
        $rub = collect($result['checks']['liability_mismatch']['by_currency'])->firstWhere('currency', 'RUB');

        $this->assertSame(129000, $rub['ledger_liability_minor']);
        $this->assertSame(129000, $rub['orders_outstanding_minor']);
        $this->assertSame(0, $rub['delta_minor']);

        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('RECON-CODE', 200, 5)]);
        app(FulfilOrder::class)->handle($order->id);

        $after = app(ReconciliationReport::class)->build();
        $rubAfter = collect($after['checks']['liability_mismatch']['by_currency'])->firstWhere('currency', 'RUB');

        $this->assertSame(0, $rubAfter['ledger_liability_minor']);
        $this->assertSame(0, $rubAfter['delta_minor']);
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function background_recovery_picks_up_stuck_orders(): void
    {
        Queue::fake();

        $stuck = $this->order(OrderStatus::DeliveryFailed, paidSecondsAgo: 3600);
        $stuck->timestamps = false;
        $stuck->updated_at = now()->subHour();
        $stuck->save();

        $this->order(OrderStatus::Paid);
        $this->order(OrderStatus::Created, paidSecondsAgo: null);

        $count = app(ResolveStuckOrders::class)->handle(stuckAfterSeconds: 60);

        $this->assertSame(1, $count);
        Queue::assertPushed(FulfilOrderJob::class, 1);
        Queue::assertPushed(fn (FulfilOrderJob $job): bool => $job->orderId === $stuck->id);
    }

    #[Test]
    public function exhausted_run_budget_removes_order_from_automatic_recovery(): void
    {
        Queue::fake();

        $order = $this->order(OrderStatus::DeliveryFailed, paidSecondsAgo: 3600);
        $order->timestamps = false;
        $order->fulfilment_runs = (int) config('ggsell.recovery.max_fulfilment_runs');
        $order->updated_at = now()->subHour();
        $order->save();

        // An exhausted order stops consuming supplier capacity and remains visible
        // for manual review in reconciliation.
        $this->assertSame(0, app(ResolveStuckOrders::class)->handle(stuckAfterSeconds: 60));
        Queue::assertNotPushed(FulfilOrderJob::class);

        $result = app(ReconciliationReport::class)->build(60);
        $this->assertSame(1, $result['checks']['paid_not_delivered']['count']);
    }

    #[Test]
    public function reconciliation_endpoint_returns_409_for_discrepancy(): void
    {
        $this->order(OrderStatus::OutOfStock, paidSecondsAgo: 3600);

        $this->getJson('/api/v1/ops/reconciliation?grace_seconds=60')
            ->assertStatus(409)
            ->assertJsonPath('healthy', false);
    }

    /**
     * A mismatched payment leaves the order created with no ledger entries. It
     * cannot appear in paid_not_delivered and therefore needs a dedicated check.
     */
    #[Test]
    public function payment_with_mismatched_amount_is_visible_in_reconciliation(): void
    {
        $order = $this->order(OrderStatus::Created, paidSecondsAgo: null);

        $this->postJson('/api/v1/webhooks/payment', [
            'event_id' => 'evt_mismatch',
            'order_id' => $order->public_id,
            'status' => 'paid',
            'amount' => 100,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ])->assertOk()->assertJson(['outcome' => 'mismatch']);

        PaymentEvent::query()->where('event_id', 'evt_mismatch')
            ->update(['received_at' => now()->subHour()]);

        $report = app(ReconciliationReport::class);
        $result = $report->build(60);

        $this->assertSame(1, $result['checks']['payments_not_applied']['count']);
        $this->assertSame(10000, $result['checks']['payments_not_applied']['amount_minor']);
        $this->assertFalse($report->isHealthy($result));
    }

    #[Test]
    public function event_without_order_is_visible_after_cutoff(): void
    {
        $this->postJson('/api/v1/webhooks/payment', [
            'event_id' => 'evt_orphan',
            'order_id' => 'ord_never_created',
            'status' => 'paid',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ])->assertOk()->assertJson(['outcome' => 'pending_order']);

        PaymentEvent::query()->where('event_id', 'evt_orphan')
            ->update(['received_at' => now()->subHour()]);

        $result = app(ReconciliationReport::class)->build(60);

        $this->assertSame(1, $result['checks']['payments_not_applied']['count']);
    }

    /**
     * A revoked payment after delivery is a direct loss. Refunds are manual, but
     * the affected order must remain visible.
     */
    #[Test]
    public function payment_failure_after_received_money_is_visible_in_reconciliation(): void
    {
        $order = $this->order(OrderStatus::Paid);

        $this->postJson('/api/v1/webhooks/payment', [
            'event_id' => 'evt_reversed',
            'order_id' => $order->public_id,
            'status' => 'failed',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ])->assertOk()->assertJson(['outcome' => 'no_op']);

        // A second failure for one order must not double the reported loss.
        $this->postJson('/api/v1/webhooks/payment', [
            'event_id' => 'evt_reversed_2',
            'order_id' => $order->public_id,
            'status' => 'failed',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => now()->addMinute()->toIso8601String(),
        ])->assertOk();

        $report = app(ReconciliationReport::class);
        $result = $report->build(60);

        $this->assertSame(1, $result['checks']['payments_reversed']['count'], 'Two events for one order must produce one row.');
        $this->assertSame(129000, $result['checks']['payments_reversed']['amount_minor'], 'The amount must not be doubled.');
        $this->assertFalse($report->isHealthy($result));
    }

    /**
     * A stale failure intentionally rejected by the domain is not a loss and must
     * not make reconciliation unhealthy under normal operation.
     */
    #[Test]
    public function stale_failure_is_not_counted_as_loss(): void
    {
        $order = $this->order(OrderStatus::Created, paidSecondsAgo: null);

        $this->postJson('/api/v1/webhooks/payment', [
            'event_id' => 'evt_paid_ok',
            'order_id' => $order->public_id,
            'status' => 'paid',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ])->assertOk()->assertJson(['outcome' => 'applied']);

        // The failure occurred before payment but was delivered afterward.
        $this->postJson('/api/v1/webhooks/payment', [
            'event_id' => 'evt_failed_stale',
            'order_id' => $order->public_id,
            'status' => 'failed',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => now()->subHour()->toIso8601String(),
        ])->assertOk()->assertJson(['outcome' => 'stale']);

        $result = app(ReconciliationReport::class)->build(60);

        $this->assertSame(0, $result['checks']['payments_reversed']['count']);
    }

    /**
     * Reconciliation-only runs must consume budget. Otherwise an unresponsive
     * supplier can keep an order retrying forever without raising the exhaustion
     * signal designed for that condition.
     */
    #[Test]
    public function reconciliation_only_run_consumes_budget(): void
    {
        $order = $this->order(OrderStatus::DeliveryFailed, paidSecondsAgo: 3600);

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => 'a',
            'request_id' => "req_{$order->public_id}_a_1",
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Unknown,
            'started_at' => now()->subHour(),
        ]);

        // The supplier still provides no definitive outcome.
        $this->supplier->script(SupplierId::A, [
            SupplierResponse::unknown('timeout', null, 2000),
            SupplierResponse::unknown('timeout', null, 2000),
        ]);

        $before = $order->fulfilment_runs;
        app(FulfilOrder::class)->handle($order->id);

        $this->assertSame($before + 1, $order->refresh()->fulfilment_runs);
    }

    #[Test]
    public function received_but_uncommitted_code_is_visible_in_reconciliation(): void
    {
        $order = $this->order(OrderStatus::Delivering, paidSecondsAgo: 3600);

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => 'a',
            'request_id' => "req_{$order->public_id}_a_1",
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'CODE-UNCOMMITTED',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);

        $result = app(ReconciliationReport::class)->build(60);

        $this->assertSame(1, $result['checks']['uncommitted_codes']['count']);
    }

    #[Test]
    public function report_does_not_expose_product_codes(): void
    {
        $order = $this->order(OrderStatus::Paid);
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('SECRET-CODE-42', 200, 5)]);
        app(FulfilOrder::class)->handle($order->id);

        $body = $this->getJson('/api/v1/ops/reconciliation?grace_seconds=0')->getContent();

        $this->assertStringNotContainsString('SECRET-CODE-42', (string) $body);
    }

    /**
     * The fifty-concurrent-webhooks scenario uses distinct paid event IDs for one
     * order. The report exposes them without becoming unhealthy under normal load.
     */
    #[Test]
    public function multiple_successful_payments_are_shown_without_failing_health_status(): void
    {
        $order = $this->order(OrderStatus::Created, paidSecondsAgo: null);

        foreach (['evt_dup_1', 'evt_dup_2', 'evt_dup_3'] as $eventId) {
            $this->postJson('/api/v1/webhooks/payment', [
                'event_id' => $eventId,
                'order_id' => $order->public_id,
                'status' => 'paid',
                'amount' => 1290,
                'currency' => 'RUB',
                'created_at' => now()->toIso8601String(),
            ])->assertOk();
        }

        $report = app(ReconciliationReport::class);
        $result = $report->build(60);

        $this->assertSame(1, $result['checks']['duplicate_payments']['count'], 'The order must be visible.');
        $this->assertTrue($result['checks']['duplicate_payments']['informational']);
        $this->assertTrue($report->isHealthy($result), 'An informational signal must not fail the health status.');
    }

    /**
     * Resolved discrepancies must leave the report so later incidents remain visible.
     */
    #[Test]
    public function resolved_orphaned_code_stops_failing_reconciliation(): void
    {
        // Real scenario: the order was delivered normally with a delivery row and
        // ledger entries, while a second run returned an extra code that lost the race.
        $order = $this->order(OrderStatus::Paid);
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('WINNING-CODE', 200, 5)]);
        app(FulfilOrder::class)->handle($order->id);
        $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);

        app(PostTransaction::class)->handle(
            lines: [
                LedgerLine::debit(Account::Cash, $order->amount_minor),
                LedgerLine::credit(Account::CustomerLiability, $order->amount_minor),
            ],
            currency: $order->currency,
            refType: 'payment_event',
            refId: 'evt_for_orphan',
            orderId: $order->id,
        );

        $attempt = DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => 'b',
            'request_id' => "req_{$order->public_id}_b_1",
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'code' => 'ORPHAN-CODE',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $orphan = OrphanedCode::create([
            'order_id' => $order->id,
            'delivery_attempt_id' => $attempt->id,
            'supplier' => 'b',
            'code' => 'ORPHAN-CODE',
            'reason' => 'lost_delivery_race',
        ]);

        $report = app(ReconciliationReport::class);
        $this->assertSame(1, $report->build(60)['checks']['orphaned_codes']['count']);
        $this->assertFalse($report->isHealthy($report->build(60)));

        $this->postJson("/api/v1/ops/orphaned-codes/{$orphan->id}/resolve", [
            'resolution' => 'code returned to supplier pool',
        ])->assertOk();

        $after = $report->build(60);
        $this->assertSame(0, $after['checks']['orphaned_codes']['count']);
        $this->assertTrue($report->isHealthy($after));

        $this->postJson("/api/v1/ops/orphaned-codes/{$orphan->id}/resolve", [
            'resolution' => 'again',
        ])->assertStatus(409);
    }

    #[Test]
    public function resolution_without_description_is_rejected(): void
    {
        $order = $this->order(OrderStatus::Delivered);
        $attempt = DeliveryAttempt::create([
            'order_id' => $order->id, 'supplier' => 'a',
            'request_id' => "req_{$order->public_id}_a_1", 'attempt_no' => 1, 'tries' => 1,
            'status' => AttemptStatus::Succeeded, 'code' => 'C1', 'started_at' => now(), 'finished_at' => now(),
        ]);
        $orphan = OrphanedCode::create([
            'order_id' => $order->id, 'delivery_attempt_id' => $attempt->id,
            'supplier' => 'a', 'code' => 'C1', 'reason' => 'lost_delivery_race',
        ]);

        $this->postJson("/api/v1/ops/orphaned-codes/{$orphan->id}/resolve", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resolution');

        $this->assertNull($orphan->refresh()->resolved_at);
    }

    /**
     * An event whose order will never exist must not occupy every replay batch and
     * starve valid webhooks that arrived before their orders.
     */
    #[Test]
    public function replay_sweep_is_not_starved_by_permanent_events(): void
    {
        $window = (int) config('ggsell.recovery.replay_window_hours');

        PaymentEvent::create([
            'event_id' => 'evt_ancient',
            'order_public_id' => 'ord_never',
            'status' => 'paid',
            'amount_minor' => 129000,
            'currency' => 'RUB',
            'payload' => [],
            'occurred_at' => now()->subHours($window + 10),
            'received_at' => now()->subHours($window + 10),
        ]);

        $applied = app(ReplayPendingEvents::class)->sweep();

        $this->assertSame(0, $applied);
        $this->assertSame(1, app(ReconciliationReport::class)->build(60)['checks']['payments_not_applied']['count']);
    }
}
