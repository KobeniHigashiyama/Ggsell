<?php

declare(strict_types=1);

namespace Tests\Feature\History;

use App\Domain\Delivery\Actions\FulfilOrderItem;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\History\Enums\OrderEventType;
use App\Domain\History\Models\OrderEvent;
use App\Domain\Ordering\Actions\CreateOrder;
use App\Domain\Ordering\Actions\SettleUnfulfillableItems;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Actions\RefundOrderItem;
use App\Domain\Refunds\Gateways\PaymentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

/**
 * Stage 2, task 4: the state of any order, and of the money, at any past moment.
 *
 * The scenario is walked with the clock moving forward so each answer is about a
 * genuinely different point in the order's life rather than a filter over its
 * final state.
 */
class OrderHistoryTest extends TestCase
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

    private function pay(Order $order): void
    {
        $this->postJson('/api/v1/webhooks/payment', [
            'event_id' => 'evt_'.$order->public_id,
            'order_id' => $order->public_id,
            'status' => 'paid',
            'amount' => number_format($order->amount_minor / 100, 2, '.', ''),
            'currency' => $order->currency,
            'created_at' => now()->toIso8601String(),
        ])->assertOk();
    }

    /**
     * Walks one order through its whole life, with the clock moving between steps.
     *
     * The queue is faked and the actions are called directly so each step lands at
     * a distinct moment. Left on the synchronous queue, payment would trigger
     * delivery inside the same second and there would be no "just after payment"
     * to ask about.
     *
     * @return array{0: Order, 1: array<string, Carbon>}
     */
    private function orderWithHistory(): array
    {
        Queue::fake();

        // 1990 + 1290, one line deliverable and one that never will be.
        $order = app(CreateOrder::class)->handle([
            ['sku' => 'KEY-GTA5', 'quantity' => 1],
            ['sku' => 'KEY-CS2-PRIME', 'quantity' => 1],
        ]);

        $moments = ['created' => now()];

        $this->travel(10)->minutes();
        $this->pay($order);
        $moments['paid'] = now();

        $this->travel(10)->minutes();
        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('GTA-CODE', 200, 5, 'KEY-GTA5'),
            SupplierResponse::rejected('out_of_stock', 409, 5),
        ]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 5)]);

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('position')->get();

        foreach ($items as $item) {
            app(FulfilOrderItem::class)->handle($item->id);
        }

        $moments['delivered'] = now();

        $this->travel(20)->minutes();

        // The scan still decides which line is hopeless; only its job is faked.
        $this->assertSame(1, app(SettleUnfulfillableItems::class)->handle());
        app(RefundOrderItem::class)->handle($items[1]->id);

        $moments['settled'] = now();

        return [$order->refresh(), $moments];
    }

    #[Test]
    public function an_order_and_its_money_can_be_rebuilt_at_any_past_moment(): void
    {
        [$order, $at] = $this->orderWithHistory();

        // Just after payment: the whole amount is owed and nothing is delivered.
        $paid = $this->getJson("/api/v1/ops/orders/{$order->public_id}/at?at=".urlencode($at['paid']->toIso8601String()))
            ->assertOk()
            ->json('data');

        $this->assertTrue($paid['existed']);
        $this->assertSame('paid', $paid['status']);
        $this->assertSame(328000, $paid['money']['paid_minor']);
        $this->assertSame(0, $paid['money']['delivered_minor']);
        $this->assertSame(328000, $paid['money']['outstanding_minor']);
        $this->assertSame(['pending', 'pending'], array_column($paid['items'], 'status'));
        // The list of lines came from the log, not from today's rows.
        $this->assertTrue($paid['composition_from_log']);

        // After the first line was delivered: half settled, half still owed.
        $delivered = $this->getJson("/api/v1/ops/orders/{$order->public_id}/at?at=".urlencode($at['delivered']->toIso8601String()))
            ->assertOk()
            ->json('data');

        $this->assertSame('out_of_stock', $delivered['status']);
        $this->assertSame(199000, $delivered['money']['delivered_minor']);
        $this->assertSame(0, $delivered['money']['refunded_minor']);
        $this->assertSame(129000, $delivered['money']['outstanding_minor']);
        // The line that failed is distinguishable from one that never started,
        // which is the whole point of recording non-terminal transitions.
        $this->assertSame(['delivered', 'out_of_stock'], array_column($delivered['items'], 'status'));

        // After settlement: nothing is owed in either direction.
        $settled = $this->getJson("/api/v1/ops/orders/{$order->public_id}/at?at=".urlencode($at['settled']->toIso8601String()))
            ->assertOk()
            ->json('data');

        $this->assertSame('partially_delivered', $settled['status']);
        $this->assertSame(199000, $settled['money']['delivered_minor']);
        $this->assertSame(129000, $settled['money']['refunded_minor']);
        $this->assertSame(0, $settled['money']['outstanding_minor']);
        $this->assertSame(['delivered', 'refunded'], array_column($settled['items'], 'status'));
    }

    #[Test]
    public function an_order_did_not_exist_before_its_first_event(): void
    {
        [$order, $at] = $this->orderWithHistory();

        $before = $this->getJson(
            "/api/v1/ops/orders/{$order->public_id}/at?at=".urlencode($at['created']->copy()->subMinute()->toIso8601String()),
        )->assertOk()->json('data');

        $this->assertFalse($before['existed']);
        $this->assertNull($before['status']);
        $this->assertSame(0, $before['money']['paid_minor']);
    }

    /**
     * The append-only guarantee is enforced by the database, so it holds for
     * anything with a connection, not only for code that goes through the domain.
     */
    #[Test]
    public function history_can_never_be_rewritten(): void
    {
        [$order] = $this->orderWithHistory();
        $event = OrderEvent::query()->where('order_id', $order->id)->firstOrFail();

        foreach ([
            fn () => DB::table('order_events')->where('id', $event->id)->update(['type' => 'tampered']),
            fn () => DB::table('order_events')->where('id', $event->id)->delete(),
            // A row trigger does not fire on TRUNCATE, so without a statement
            // trigger the whole log could be erased in one statement.
            fn () => DB::statement('TRUNCATE order_events'),
        ] as $rewrite) {
            try {
                // A savepoint, so the refused statement does not abort the test's
                // own transaction along with it.
                DB::transaction($rewrite);
                $this->fail('Rewriting history must be refused by the database.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertDatabaseHas('order_events', ['id' => $event->id, 'type' => $event->type->value]);
    }

    /**
     * The ledger is the independent side of every money check, so it has to be
     * as unrewritable as the log it is checked against.
     */
    #[Test]
    public function the_ledger_can_never_be_rewritten_either(): void
    {
        $this->orderWithHistory();
        $entry = DB::table('ledger_entries')->first();

        foreach ([
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount_minor' => 1]),
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete(),
            fn () => DB::statement('TRUNCATE ledger_entries'),
        ] as $rewrite) {
            try {
                DB::transaction($rewrite);
                $this->fail('Rewriting the ledger must be refused by the database.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertDatabaseHas('ledger_entries', [
            'id' => $entry->id,
            'amount_minor' => $entry->amount_minor,
        ]);
    }

    #[Test]
    public function a_period_adds_up_and_agrees_with_the_ledger(): void
    {
        [, $at] = $this->orderWithHistory();

        $report = $this->getJson(
            '/api/v1/ops/reports/period?from='.urlencode($at['created']->copy()->subHour()->toIso8601String())
            .'&to='.urlencode($at['settled']->copy()->addHour()->toIso8601String()),
        )->assertOk()->json();

        $this->assertSame(328000, $report['by_business_time']['paid_minor']);
        $this->assertSame(199000, $report['by_business_time']['delivered_minor']);
        $this->assertSame(129000, $report['by_business_time']['refunded_minor']);
        // Revenue is what was delivered. The refund was for a line that never
        // was, so it does not reduce it; what it reduces is the cash kept.
        $this->assertSame(199000, $report['by_business_time']['revenue_minor']);
        $this->assertSame(199000, $report['by_business_time']['cash_retained_minor']);
        $this->assertSame(1, $report['by_business_time']['orders']);

        $this->assertTrue($report['ledger_check']['matches'], json_encode($report['ledger_check']));
        $this->assertSame(
            ['paid_minor' => 0, 'delivered_minor' => 0, 'refunded_minor' => 0],
            $report['ledger_check']['deltas'],
        );
    }

    #[Test]
    public function a_window_that_excludes_the_payment_reports_only_what_happened_inside_it(): void
    {
        [, $at] = $this->orderWithHistory();

        // From just after the payment to just after the delivery.
        $report = $this->getJson(
            '/api/v1/ops/reports/period?from='.urlencode($at['paid']->copy()->addSecond()->toIso8601String())
            .'&to='.urlencode($at['delivered']->copy()->addSecond()->toIso8601String()),
        )->assertOk()->json('by_business_time');

        $this->assertSame(0, $report['paid_minor'], 'The payment belongs to the earlier window.');
        $this->assertSame(199000, $report['delivered_minor']);
        $this->assertSame(0, $report['refunded_minor']);
    }

    #[Test]
    public function the_log_records_the_business_time_of_a_late_webhook(): void
    {
        $order = app(CreateOrder::class)->handle([['sku' => 'KEY-GTA5']]);
        $paidAt = now();

        // The gateway took five minutes to deliver the webhook.
        $this->travel(5)->minutes();

        $this->postJson('/api/v1/webhooks/payment', [
            'event_id' => 'evt_late',
            'order_id' => $order->public_id,
            'status' => 'paid',
            'amount' => '1990.00',
            'currency' => 'RUB',
            'created_at' => $paidAt->toIso8601String(),
        ])->assertOk();

        $event = OrderEvent::query()->where('type', OrderEventType::PaymentApplied->value)->sole();

        $this->assertSame($paidAt->toIso8601String(), $event->occurred_at->toIso8601String());
        $this->assertTrue($event->created_at->greaterThan($event->occurred_at));
    }
}
