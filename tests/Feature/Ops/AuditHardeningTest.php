<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Domain\Delivery\Actions\CommitDelivery;
use App\Domain\Delivery\Actions\DeliveryCommitResult;
use App\Domain\Delivery\Actions\FulfilOrderItem;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierRateLimiter;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ops\Recovery\AutoResolveDiscrepancies;
use App\Domain\Ops\Recovery\ResolveStuckDeliveries;
use App\Domain\Ordering\Actions\SettleUnfulfillableItems;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Actions\RefundOrderItem;
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
 * Regressions found by auditing stage 2 against its own claims.
 *
 * Every case here is a path that reached an irreversible write while going
 * around a guard that was only enforced somewhere else, or a line that could
 * never reach a terminal state because nothing was left to move it.
 */
class AuditHardeningTest extends TestCase
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

    private function paidOrder(): Order
    {
        return $this->makeOrder('KEY-CS2-PRIME', [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
        ]);
    }

    private function failedAttemptFor(OrderItem $item, ?string $hiddenCode = null): DeliveryAttempt
    {
        $attempt = DeliveryAttempt::create([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'supplier' => SupplierId::A->value,
            'request_id' => 'req_'.$item->public_id.'_a_1',
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Failed,
            'http_status' => 503,
            'error_reason' => 'supplier_error',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
        ]);

        if ($hiddenCode !== null) {
            // The supplier's registry holds a code it never admitted to issuing.
            $this->supplier->alreadyIssued($attempt->request_id, $hiddenCode, $item->sku);
        }

        return $attempt;
    }

    /**
     * The audit sweep was the third way to reach a delivery, and the only one
     * that did not check whether the money was already going back.
     */
    #[Test]
    public function a_code_found_by_the_audit_is_not_delivered_while_a_refund_is_in_flight(): void
    {
        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill(['status' => OrderItemStatus::DeliveryFailed, 'failure_reason' => 'supplier_error'])->save();

        $this->failedAttemptFor($item, 'CODE-BEHIND-THE-ERROR');

        // A refund was started and the gateway has not answered yet.
        Refund::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'refund_request_id' => Refund::makeRequestId($item),
            'amount_minor' => $item->amount_minor,
            'currency' => $item->currency,
            'status' => RefundStatus::Unknown,
            'reason' => 'supplier_error',
            'requested_at' => now()->subMinute(),
            'tries' => 1,
        ]);

        app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertSame(0, DB::table('deliveries')->count(), 'Delivering now would hand over the product and the money.');
        $this->assertNotSame(OrderItemStatus::Delivered, $item->refresh()->status);

        // The code is still paid inventory, so it stays visible rather than lost.
        $this->assertSame(1, DB::table('orphaned_codes')->where('code', 'CODE-BEHIND-THE-ERROR')->count());
        $this->assertMoneyConserved();
        $this->assertLedgerBalanced();
    }

    /**
     * An unresolved outcome used to be a dead end once the retry budget was gone:
     * the line could not be delivered, could not be refunded, and nothing asked
     * the supplier again.
     */
    #[Test]
    public function an_unresolved_attempt_is_closed_by_the_audit_and_frees_its_line(): void
    {
        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill([
            'status' => OrderItemStatus::DeliveryFailed,
            'failure_reason' => 'unresolved_attempt',
            'fulfilment_runs' => (int) config('ggsell.recovery.max_fulfilment_runs'),
        ])->save();

        // The outcome was never learned, and the supplier has no record of it.
        DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'supplier' => SupplierId::A->value,
            'request_id' => 'req_'.$item->public_id.'_a_1',
            'attempt_no' => 1,
            'tries' => 2,
            'status' => AttemptStatus::Unknown,
            'started_at' => now()->subMinutes(10),
        ]);

        // Nothing can settle this line while the outcome is open.
        $this->assertSame(0, app(SettleUnfulfillableItems::class)->handle());

        $result = app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertSame(1, $result['resolved_unknown']);
        $this->assertSame(AttemptStatus::Failed, DeliveryAttempt::query()->sole()->status);

        // With the outcome settled, the money can go back.
        $this->assertSame(1, app(SettleUnfulfillableItems::class)->handle());
        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
        $this->assertMoneyConserved();
    }

    /**
     * A process killed on its last allowed run left the line in delivering with
     * the budget spent: recovery skipped it for having no budget, settlement
     * skipped it for not having failed.
     */
    #[Test]
    public function a_line_stranded_mid_delivery_with_no_budget_left_still_reaches_a_terminal_state(): void
    {
        $order = $this->paidOrder();
        $this->itemOf($order)->forceFill([
            'status' => OrderItemStatus::Delivering,
            'fulfilment_runs' => (int) config('ggsell.recovery.max_fulfilment_runs'),
        ])->save();

        $this->assertSame(1, app(SettleUnfulfillableItems::class)->handle());

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertTrue($order->status->isTerminal());
        $this->assertNotNull($order->settled_at);
        $this->assertMoneyConserved();
    }

    /**
     * The audit talks to the supplier, so it spends the same allowance as a
     * delivery. Otherwise a large incident would quietly double the traffic the
     * supplier agreed to take.
     */
    #[Test]
    public function the_audit_sweep_respects_the_supplier_rate_limit(): void
    {
        config([
            'ggsell.suppliers.rate_limit.a' => 2,
            // No reserve, so this test is about an exhausted window and nothing else.
            'ggsell.suppliers.delivery_reserve_share' => 0.0,
        ]);
        $this->travelTo(now()->startOfMinute());

        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill(['status' => OrderItemStatus::DeliveryFailed])->save();
        $this->failedAttemptFor($item);

        // Both of this minute's requests are spent elsewhere.
        $limiter = app(SupplierRateLimiter::class);
        $this->assertTrue($limiter->tryAcquire(SupplierId::A));
        $this->assertTrue($limiter->tryAcquire(SupplierId::A));
        $this->assertSame(0, $limiter->remaining(SupplierId::A));

        app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertSame([], $this->supplier->verifyCalls, 'The audit must not exceed the supplier allowance.');
        $this->assertNull(DeliveryAttempt::query()->sole()->verified_at, 'An unasked question is not an answer.');
    }

    /**
     * Background questions must not spend the allowance a paying customer needs.
     */
    #[Test]
    public function the_audit_yields_supplier_allowance_to_deliveries(): void
    {
        config([
            'ggsell.suppliers.rate_limit.a' => 10,
            'ggsell.suppliers.delivery_reserve_share' => 0.5,
        ]);

        // The whole scenario lives inside one allowance window; a run that
        // crossed a minute boundary would see it reset mid-assertion.
        $this->travelTo(now()->startOfMinute());

        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill(['status' => OrderItemStatus::DeliveryFailed])->save();
        $this->failedAttemptFor($item, 'CODE-BEHIND-THE-ERROR');

        $limiter = app(SupplierRateLimiter::class);

        // Spend down to the reserve: what is left belongs to deliveries.
        for ($i = 0; $i < 5; $i++) {
            $limiter->tryAcquire(SupplierId::A);
        }

        app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);
        $this->assertSame([], $this->supplier->verifyCalls, 'The audit must leave the reserve alone.');

        // With the window fresh, the same sweep goes ahead.
        $this->travel(61)->seconds();
        app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertCount(1, $this->supplier->verifyCalls);
    }

    /**
     * A reserve that rounds up to the whole window would stop the audit entirely
     * rather than slow it down, which is how clean-up silently stops happening on
     * a supplier with a small allowance.
     */
    #[Test]
    public function a_reserve_never_consumes_the_whole_window(): void
    {
        config([
            'ggsell.suppliers.rate_limit.a' => 1,
            'ggsell.suppliers.delivery_reserve_share' => 0.5,
        ]);
        $this->travelTo(now()->startOfMinute());

        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill(['status' => OrderItemStatus::DeliveryFailed])->save();
        $this->failedAttemptFor($item);

        app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertCount(1, $this->supplier->verifyCalls, 'With the window free, background work must still run.');
    }

    /**
     * The clean-up must never break a delivery that already happened.
     *
     * A duplicated code is the one code that belongs to somebody: handing it back
     * revokes it, and the customer holding it finds out only when it fails.
     */
    #[Test]
    public function a_code_a_customer_already_holds_is_never_handed_back(): void
    {
        $first = $this->paidOrder();
        $second = $this->paidOrder();

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('SOLD-TWICE', 200, 5, 'KEY-CS2-PRIME'),
            SupplierResponse::ok('SOLD-TWICE', 200, 5, 'KEY-CS2-PRIME'),
        ]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('HONEST-CODE', 200, 5, 'KEY-CS2-PRIME')]);

        app(FulfilOrderItem::class)->handle($this->itemOf($first)->id);
        app(FulfilOrderItem::class)->handle($this->itemOf($second)->id);

        $this->assertSame('SOLD-TWICE', $this->deliveredCode($first));
        $this->assertSame('HONEST-CODE', $this->deliveredCode($second));

        app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertNotContains(
            'SOLD-TWICE',
            array_column($this->supplier->returnedCodes, 'code'),
            'Returning it would revoke the code the first customer is holding.',
        );

        // The incident is still closed, just not at that customer's expense.
        $this->assertSame(0, DB::table('orphaned_codes')->whereNull('resolved_at')->count());
        $this->assertStringContainsString(
            'delivered line',
            (string) DB::table('orphaned_codes')->where('code', 'SOLD-TWICE')->value('resolution'),
        );
        $this->assertSame('SOLD-TWICE', $this->deliveredCode($first->refresh()));
    }

    /**
     * Being unable to ask is not the same as being told nothing was issued.
     */
    #[Test]
    public function an_unreachable_supplier_never_closes_an_attempt(): void
    {
        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill([
            'status' => OrderItemStatus::DeliveryFailed,
            'failure_reason' => 'unresolved_attempt',
            'fulfilment_runs' => (int) config('ggsell.recovery.max_fulfilment_runs'),
        ])->save();

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'supplier' => SupplierId::A->value,
            'request_id' => 'req_'.$item->public_id.'_a_1',
            'attempt_no' => 1,
            'tries' => 2,
            'status' => AttemptStatus::Unknown,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->supplier->unreachable(SupplierId::A);

        $result = app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertSame(0, $result['resolved_unknown']);

        $attempt = DeliveryAttempt::query()->sole();
        $this->assertSame(AttemptStatus::Unknown, $attempt->status);
        $this->assertNull($attempt->verified_at, 'An unanswered question must be asked again.');

        // And the line stays blocked, which is the safe state: a code may exist.
        $this->assertSame(0, app(SettleUnfulfillableItems::class)->handle());
    }

    /**
     * A crash between finding a code and placing it must not hide the code from
     * the next sweep.
     */
    #[Test]
    public function a_code_found_but_never_placed_is_picked_up_again(): void
    {
        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill(['status' => OrderItemStatus::DeliveryFailed])->save();

        // The state a crash leaves behind: audited, code stored, nowhere placed.
        $attempt = $this->failedAttemptFor($item, 'FOUND-BUT-LOST');
        $attempt->forceFill([
            'code' => 'FOUND-BUT-LOST',
            'reported_sku' => $item->sku,
            'verified_at' => now()->subMinute(),
        ])->save();

        app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertSame('FOUND-BUT-LOST', $this->deliveredCode($order));
        $this->assertMoneyConserved();
    }

    /**
     * A code already paid for is not a new request, so a spent retry budget must
     * not be what stops it from reaching the customer.
     */
    #[Test]
    public function a_stored_code_is_delivered_even_with_the_retry_budget_spent(): void
    {
        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill([
            'status' => OrderItemStatus::DeliveryFailed,
            'failure_reason' => 'run_budget_exhausted',
            'fulfilment_runs' => (int) config('ggsell.recovery.max_fulfilment_runs'),
        ])->save();

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'supplier' => SupplierId::A->value,
            'request_id' => 'req_'.$item->public_id.'_a_1',
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'PAID-FOR-ALREADY',
            'reported_sku' => $item->sku,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
        ]);

        // Background recovery must see it despite the spent budget.
        DB::table('order_items')->update(['updated_at' => now()->subHour()]);
        $this->assertSame(1, app(ResolveStuckDeliveries::class)->handle(stuckAfterSeconds: 60));

        app(FulfilOrderItem::class)->handle($item->id);

        $this->assertSame('PAID-FOR-ALREADY', $this->deliveredCode($order));
        $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);
        $this->assertSame([], $this->supplier->calls, 'The code was already bought; no supplier call is needed.');
        $this->assertMoneyConserved();
    }

    /**
     * The write refuses a code somebody already judged undeliverable.
     *
     * Validation catches this earlier in the normal flow, but the sweep that
     * hands stranded codes back runs on its own schedule: a code can become
     * stranded after a delivery has been validated and before it is written. The
     * guarantee therefore has to hold at the write, which is also where the two
     * decisions are serialized against each other.
     */
    #[Test]
    public function a_code_already_recorded_as_stranded_is_refused_at_the_write(): void
    {
        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $attempt = $this->failedAttemptFor($item);

        DB::table('orphaned_codes')->insert([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'delivery_attempt_id' => $attempt->id,
            'supplier' => SupplierId::A->value,
            'code' => 'STRANDED-CODE',
            'reason' => 'issued_after_error',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(CommitDelivery::class)->handle($item, $attempt, 'STRANDED-CODE');

        $this->assertSame(DeliveryCommitResult::CodeTaken, $result);
        $this->assertTrue($result->isSupplierAtFault());
        $this->assertSame(0, DB::table('deliveries')->count());
        $this->assertMoneyConserved();
    }

    /**
     * A refund job can be delayed long enough for its line to be retried. The
     * rule about which lines may be refunded has to hold when the money moves,
     * not only when the scan picked the line.
     */
    #[Test]
    public function a_line_that_went_back_into_delivery_is_not_refunded(): void
    {
        $order = $this->paidOrder();
        $item = $this->itemOf($order);
        $item->forceFill(['status' => OrderItemStatus::Delivering])->save();

        $this->assertNull(app(RefundOrderItem::class)->handle($item->id));

        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertMoneyConserved();
    }

    /**
     * A code stored by a run that died before validating it must still be checked
     * before it reaches a customer.
     */
    #[Test]
    public function a_stored_code_with_no_provenance_is_refused_on_recovery(): void
    {
        $order = $this->paidOrder();
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
            'code' => 'UNATTRIBUTED-CODE',
            // The supplier never said what this code was for.
            'reported_sku' => null,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
        ]);

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('out_of_stock', 409, 8)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        app(FulfilOrderItem::class)->handle($item->id);

        $this->assertSame(0, DB::table('deliveries')->count());
        $this->assertNotNull(DeliveryAttempt::query()->sole()->quarantined_at);
        $this->assertSame('unverified_code', $item->refresh()->failure_reason);
    }
}
