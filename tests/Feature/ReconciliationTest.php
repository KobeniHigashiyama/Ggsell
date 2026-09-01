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
    public function на_чистой_системе_расхождений_нет(): void
    {
        $report = app(ReconciliationReport::class);
        $result = $report->build();

        $this->assertTrue($report->isHealthy($result), json_encode($result['checks'], JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function оплаченный_но_не_выданный_заказ_попадает_в_сверку(): void
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
    public function сальдо_обязательств_совпадает_с_суммой_невыданных_заказов(): void
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
    public function фоновое_дожатие_подхватывает_зависшие_заказы(): void
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
    public function исчерпанный_бюджет_прогонов_снимает_заказ_с_автодожатия(): void
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
    public function эндпоинт_сверки_отдаёт_409_при_расхождении(): void
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
    public function платёж_с_несовпавшей_суммой_виден_в_сверке(): void
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
    public function событие_без_заказа_после_отсечки_видно_в_сверке(): void
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
    public function отказ_платежа_после_полученных_денег_виден_в_сверке(): void
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

        $this->assertSame(1, $result['checks']['payments_reversed']['count'], 'Два события по одному заказу — одна строка.');
        $this->assertSame(129000, $result['checks']['payments_reversed']['amount_minor'], 'Сумма не должна удваиваться.');
        $this->assertFalse($report->isHealthy($result));
    }

    /**
     * A stale failure intentionally rejected by the domain is not a loss and must
     * not make reconciliation unhealthy under normal operation.
     */
    #[Test]
    public function протухший_отказ_не_считается_убытком(): void
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
    public function прогон_из_одной_досверки_тратит_бюджет(): void
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
    public function полученный_но_не_зафиксированный_код_виден_в_сверке(): void
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
    public function отчёт_не_раскрывает_коды_товара(): void
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
    public function несколько_успешных_платежей_показываются_но_не_гасят_зелёный_статус(): void
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

        $this->assertSame(1, $result['checks']['duplicate_payments']['count'], 'Заказ обязан быть виден.');
        $this->assertTrue($result['checks']['duplicate_payments']['informational']);
        $this->assertTrue($report->isHealthy($result), 'Информационный сигнал не гасит зелёный статус.');
    }

    /**
     * Resolved discrepancies must leave the report so later incidents remain visible.
     */
    #[Test]
    public function разобранный_осиротевший_код_перестаёт_держать_сверку_красной(): void
    {
        $order = $this->order(OrderStatus::Delivered);

        $attempt = DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => 'a',
            'request_id' => "req_{$order->public_id}_a_1",
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
            'supplier' => 'a',
            'code' => 'ORPHAN-CODE',
            'reason' => 'lost_delivery_race',
        ]);

        $report = app(ReconciliationReport::class);
        $this->assertSame(1, $report->build(60)['checks']['orphaned_codes']['count']);
        $this->assertFalse($report->isHealthy($report->build(60)));

        $this->postJson("/api/v1/ops/orphaned-codes/{$orphan->id}/resolve", [
            'resolution' => 'ключ возвращён в пул поставщика',
        ])->assertOk();

        $after = $report->build(60);
        $this->assertSame(0, $after['checks']['orphaned_codes']['count']);
        $this->assertTrue($report->isHealthy($after));

        $this->postJson("/api/v1/ops/orphaned-codes/{$orphan->id}/resolve", [
            'resolution' => 'ещё раз',
        ])->assertStatus(409);
    }

    #[Test]
    public function разбор_без_описания_отвергается(): void
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
    public function метёлка_реплея_не_забивается_вечными_событиями(): void
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
