<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Enums\ViolationKind;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\CircuitBreaker;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierRateLimiter;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\History\Actions\RecordOrderEvent;
use App\Domain\History\Enums\OrderEventType;
use App\Domain\Ordering\Actions\RecalculateOrderStatus;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use App\Support\Log\DeliveryLog;
use Illuminate\Support\Facades\DB;

/**
 * Delivery orchestrator for one order item.
 *
 * The ordering of these steps is the core invariant of this class:
 *
 *   1. Lock the item and verify that delivery is required.
 *   2. Reconcile every unresolved attempt before making a new request.
 *   3. Only then proceed through the supplier chain.
 *
 * Reconciliation must precede new requests because a timed-out supplier may have
 * already issued a code. Starting a new request could issue a second key.
 *
 * The method is idempotent because webhooks, background recovery, and manual
 * recovery may all invoke it for an already delivered item.
 *
 * Locks are always taken order first, then item, so two items of the same order
 * being fulfilled in parallel serialize instead of deadlocking. Supplier calls
 * happen outside every transaction, so that lock is never held across the network.
 */
final readonly class FulfilOrderItem
{
    public function __construct(
        private SupplierClient $client,
        private CircuitBreaker $breaker,
        private CommitDelivery $commitDelivery,
        private ReconcileUnknownAttempt $reconcileAttempt,
        private RecalculateOrderStatus $recalculateOrderStatus,
        private ValidateSupplierCode $validateCode,
        private QuarantineUntrustedCode $quarantineCode,
        private SupplierRateLimiter $rateLimiter,
        private RecordOrderEvent $recordEvent,
    ) {}

    /**
     * @return bool False when no supplier had capacity and the line must be tried
     *              again later. The caller re-queues; nothing is lost and no
     *              status is changed on the way out.
     */
    public function handle(int $orderItemId): bool
    {
        // Checked before claiming so a spike costs no run budget: waiting for
        // capacity is not an attempt at delivery.
        if (! $this->rateLimiter->hasCapacityForAnySupplier() && $this->needsSupplier($orderItemId)) {
            DeliveryLog::info('fulfilment.deferred_rate_limit', ['order_item_id' => $orderItemId]);

            return false;
        }

        $item = $this->claim($orderItemId);

        if ($item === null) {
            return true;
        }

        if ($this->commitAlreadyIssuedCode($item)) {
            return true;
        }

        if ($this->resolveOutstandingAttempts($item)) {
            return true;
        }

        return $this->walkSupplierChain($item);
    }

    /**
     * True when finishing this line would require calling a supplier.
     *
     * A line holding a code, or one that is already settled, has work to do that
     * costs the supplier nothing, so a rate limit must not delay it.
     */
    private function needsSupplier(int $orderItemId): bool
    {
        $item = OrderItem::query()->find($orderItemId);

        return $item !== null
            && ! $item->status->isSettled()
            && ! $item->attempts()->holdingUsableCode()->exists();
    }

    /**
     * Acquires row locks on the order and the item.
     *
     * Returns null when delivery is unnecessary or impossible. Keeping every
     * eligibility check here gives workers, schedulers, and manual recovery the
     * same decision.
     */
    private function claim(int $orderItemId): ?OrderItem
    {
        return DB::transaction(function () use ($orderItemId): ?OrderItem {
            $orderId = OrderItem::query()->whereKey($orderItemId)->value('order_id');

            if ($orderId === null) {
                return null;
            }

            $order = Order::query()->lockForUpdate()->find($orderId);
            $item = OrderItem::query()->lockForUpdate()->find($orderItemId);

            if ($order === null || $item === null) {
                return null;
            }

            if ($item->status->isSettled()) {
                return null;
            }

            if (! $order->status->moneyReceived()) {
                DeliveryLog::warning('fulfilment.refused_unpaid', [
                    'order_id' => $order->public_id,
                    'item_id' => $item->public_id,
                    'order_status' => $order->status->value,
                ]);

                return null;
            }

            if ($this->moneyIsGoingBack($item)) {
                // A refund is in flight or already done. Stopping here saves a
                // pointless supplier call; the guarantee itself lives in
                // CommitDelivery, which refuses the write however it is reached.
                DeliveryLog::warning('fulfilment.refused_refund_in_flight', [
                    'order_id' => $order->public_id,
                    'item_id' => $item->public_id,
                ]);

                return null;
            }

            // Repair a stale status when the delivery row already exists.
            if ($item->delivery()->exists()) {
                if ($item->tryTransitionTo(OrderItemStatus::Delivered)) {
                    $item->delivered_at ??= now();
                    $item->settled_at ??= now();
                    $item->save();
                    $this->recalculateOrderStatus->handle($order);
                }

                return null;
            }

            $maxRuns = (int) config('ggsell.recovery.max_fulfilment_runs');

            // A line already holding a code is finishing work the supplier has
            // been paid for, not starting new work, so the budget does not apply.
            // Gating it here was a deadlock: the code could not be committed, the
            // refund was blocked by that same code, and nothing else looks at a
            // succeeded attempt.
            $holdsCode = $item->attempts()->holdingUsableCode()->exists();

            if (! $holdsCode && $item->fulfilment_runs >= $maxRuns) {
                // Exhausted items stop consuming supplier capacity and queue time.
                // They are parked in a recoverable state rather than left mid-flight,
                // because settlement can only refund a line whose delivery concluded,
                // and an order stuck in delivering would never reach a terminal state.
                $before = $item->status;

                if ($item->tryTransitionTo(OrderItemStatus::DeliveryFailed)) {
                    $item->failure_reason = 'run_budget_exhausted';
                    $item->save();
                    $this->recordItemStatus($item, $before);
                    $this->recalculateOrderStatus->handle($order);
                }

                DeliveryLog::error('fulfilment.run_budget_exhausted', [
                    'order_id' => $order->public_id,
                    'item_id' => $item->public_id,
                    'runs' => $item->fulfilment_runs,
                ]);

                return null;
            }

            // Delivering means resuming an item whose previous run ended before
            // recording a final status, not performing another transition.
            $before = $item->status;

            if ($item->status !== OrderItemStatus::Delivering && ! $item->tryTransitionTo(OrderItemStatus::Delivering)) {
                return null;
            }

            if ($item->status !== $before) {
                $this->recordItemStatus($item, $before);
            }

            // Every run consumes budget, including reconciliation-only runs. Otherwise
            // a long-lived unknown attempt could be retried forever without raising the
            // run_budget_exhausted operational signal. Manual recovery resets the count.
            $item->fulfilment_runs++;
            $item->save();

            $this->recalculateOrderStatus->handle($order);

            return $item;
        });
    }

    /**
     * Records a line moving between non-terminal states.
     *
     * Delivered and refunded have their own events, written where the money moves.
     * This covers everything in between, which is what makes a partly failed order
     * readable in history instead of a row of identical "awaiting" lines.
     *
     * The caller already holds the order and item locks, so the sequence used as
     * the idempotency key cannot be claimed twice.
     */
    private function recordItemStatus(OrderItem $item, OrderItemStatus $from): void
    {
        $this->recordEvent->handle(
            type: OrderEventType::ItemStatusChanged,
            orderId: $item->order_id,
            orderItemId: $item->id,
            payload: [
                'from' => $from->value,
                'to' => $item->status->value,
                'reason' => $item->failure_reason,
                'sku' => $item->sku,
            ],
            refType: 'item_status',
            refId: $item->id.':'.$this->recordEvent->nextSequence(
                OrderEventType::ItemStatusChanged,
                $item->order_id,
                $item->id,
            ),
        );
    }

    /**
     * True when a refund for this line has started and was not definitively
     * rejected.
     *
     * Only a failed refund proves the money stayed with us; pending and unknown
     * both mean it may already be on its way back to the customer.
     */
    private function moneyIsGoingBack(OrderItem $item): bool
    {
        return Refund::query()
            ->where('order_item_id', $item->id)
            ->where('status', '!=', RefundStatus::Failed->value)
            ->exists();
    }

    /**
     * Step 2: recover a code returned before its delivery was committed.
     *
     * The successful response and delivery row are stored in separate transactions.
     * If the process stops between them, the succeeded attempt contains the code
     * but no delivery exists. Recovering it prevents a new request_id and key.
     *
     * No supplier call is needed because the code is already stored.
     *
     * @return bool True when this run is complete.
     */
    private function commitAlreadyIssuedCode(OrderItem $item): bool
    {
        $issued = $item->attempts()->holdingUsableCode()->orderBy('id')->first();

        if ($issued === null) {
            return false;
        }

        DeliveryLog::warning('fulfilment.recovered_uncommitted_code', [
            'item_id' => $item->public_id,
            'supplier' => $issued->supplier->value,
            'request_id' => $issued->request_id,
        ]);

        // A code stored by an earlier run has not necessarily been validated: the
        // response is saved before the check, so a crash in between leaves one
        // behind. Re-check it against what the supplier claimed at the time.
        $violation = $this->validateCode->handle($item, (string) $issued->code, $issued->reported_sku);

        if ($violation !== null) {
            $this->quarantineCode->handle($item, $issued, (string) $issued->code, $violation, $issued->reported_sku);
            $this->settle($item, OrderItemStatus::DeliveryFailed, $violation->value);

            return true;
        }

        $result = $this->commitDelivery->handle($item, $issued, (string) $issued->code);

        if ($result->isDelivered()) {
            return true;
        }

        if (! $result->isSupplierAtFault()) {
            // The money is already going back; the code stays recorded as stranded.
            return true;
        }

        // The stored code turned out to belong to another item. Quarantine it so
        // no later run tries the same code again, and let the next run start a
        // fresh attempt: the customer is still owed a code.
        $this->quarantineCode->handle($item, $issued, (string) $issued->code, ViolationKind::DuplicateCode);
        $this->settle($item, OrderItemStatus::DeliveryFailed, ViolationKind::DuplicateCode->value);

        return true;
    }

    /**
     * Step 3: reconcile attempts whose outcome is unknown.
     *
     * @return bool True when delivery completed or the outcome remains unknown,
     *              which prevents fallback to another supplier.
     */
    private function resolveOutstandingAttempts(OrderItem $item): bool
    {
        $outstanding = $item->attempts()->unresolved()->orderBy('id')->get();

        foreach ($outstanding as $attempt) {
            $response = $this->reconcileAttempt->handle($attempt, $item);

            // Use the persisted attempt status rather than the raw response because
            // a reconciliation transport failure cannot close an unresolved attempt.
            if ($attempt->status === AttemptStatus::Succeeded) {
                // A code recovered through reconciliation gets the same scrutiny as
                // a fresh one: the outcome was in doubt, the supplier never was.
                if ($this->refuseUntrustedCode($item, $attempt, $response)) {
                    return true;
                }

                // The supplier issued the code that a new request could duplicate.
                $result = $this->commitDelivery->handle($item, $attempt, (string) $attempt->code);

                if (! $result->isDelivered() && $result->isSupplierAtFault()) {
                    $this->quarantineCode->handle($item, $attempt, (string) $attempt->code, ViolationKind::DuplicateCode);
                    $this->settle($item, OrderItemStatus::DeliveryFailed, ViolationKind::DuplicateCode->value);
                }

                return true;
            }

            if (! $attempt->status->allowsFallback()) {
                // The outcome remains unknown, so fallback is prohibited.
                $this->parkUnresolved($item, $attempt);

                return true;
            }

            // Failed confirms that no code was issued, allowing the next supplier.
        }

        return false;
    }

    /**
     * Refuses a reconciled code that fails validation.
     *
     * @return bool True when the code was quarantined and this run is finished.
     */
    private function refuseUntrustedCode(OrderItem $item, DeliveryAttempt $attempt, SupplierResponse $response): bool
    {
        $violation = $this->validateCode->handle($item, (string) $attempt->code, $response->sku);

        if ($violation === null) {
            return false;
        }

        $this->quarantineCode->handle($item, $attempt, (string) $attempt->code, $violation, $response->sku);
        $this->settle($item, OrderItemStatus::DeliveryFailed, $violation->value);

        return true;
    }

    /** @return bool False when the line was deferred by a supplier rate limit. */
    private function walkSupplierChain(OrderItem $item): bool
    {
        $lastReason = null;
        $rateLimited = false;

        foreach (SupplierId::chain() as $supplier) {
            if (! $this->breaker->allows($supplier)) {
                DeliveryLog::warning('supplier.skipped_breaker_open', [
                    'item_id' => $item->public_id,
                    'supplier' => $supplier->value,
                ]);

                $lastReason ??= 'breaker_open';

                continue;
            }

            if (! $this->rateLimiter->tryAcquire($supplier)) {
                // The supplier agreed to a rate and this request would exceed it.
                // Skipping is not a failure: the line keeps its state and comes
                // back when there is capacity.
                DeliveryLog::info('supplier.skipped_rate_limit', [
                    'item_id' => $item->public_id,
                    'supplier' => $supplier->value,
                ]);

                $rateLimited = true;

                continue;
            }

            $attempt = $this->openAttempt($item, $supplier);

            if ($attempt === null) {
                return true;
            }

            $response = $this->askSupplier($item, $attempt);

            if ($attempt->status === AttemptStatus::Succeeded) {
                $violation = $this->validateCode->handle($item, (string) $attempt->code, $response->sku);

                if ($violation !== null) {
                    // The code is unusable, so this supplier failed even though it
                    // answered 200. Quarantine it and move on to the next one: the
                    // key it consumed is recorded and returned by reconciliation.
                    $this->quarantineCode->handle($item, $attempt, (string) $attempt->code, $violation, $response->sku);
                    $lastReason = $violation->value;

                    continue;
                }

                $this->breaker->recordSuccess($supplier);
                $result = $this->commitDelivery->handle($item, $attempt, (string) $attempt->code);

                if ($result->isDelivered() || ! $result->isSupplierAtFault()) {
                    // Delivered, or refused because the money is already going
                    // back. Either way this line is finished for now.
                    return true;
                }

                // Another item won the race for this code between the check and the
                // insert. Same incident, caught by the unique index instead.
                $this->quarantineCode->handle($item, $attempt, (string) $attempt->code, ViolationKind::DuplicateCode);
                $lastReason = ViolationKind::DuplicateCode->value;

                continue;
            }

            // Empty stock is not a supplier health failure and must not affect
            // the circuit breaker.
            if (! $response->isOutOfStock()) {
                $this->breaker->recordFailure($supplier);
            }

            if (! $attempt->status->allowsFallback()) {
                // Retries are exhausted but the supplier may have issued a code, so
                // fallback remains unsafe. Background recovery will reconcile the item.
                $this->parkUnresolved($item, $attempt);

                return true;
            }

            $lastReason = $response->reason;
        }

        if ($rateLimited) {
            // At least one supplier was never asked, because its allowance was
            // spent. Settling now would turn a rate limit into a failed delivery
            // and eventually into a refund, which is exactly the lost order the
            // limit is not supposed to cause. Wait and ask the rest later.
            return false;
        }

        $this->settle(
            $item,
            // A shortage is resolved by replenishing inventory, not by repeatedly
            // querying the same suppliers.
            $lastReason === 'out_of_stock' ? OrderItemStatus::OutOfStock : OrderItemStatus::DeliveryFailed,
            $lastReason ?? 'all_suppliers_failed',
        );

        return true;
    }

    /**
     * Retries within a single request_id.
     *
     * attempt_no and the attempt row remain unchanged. The supplier sees the same
     * request regardless of retry count, making retries after a timeout safe.
     */
    private function askSupplier(OrderItem $item, DeliveryAttempt $attempt): SupplierResponse
    {
        $maxTries = (int) config('ggsell.suppliers.max_attempts');
        $response = SupplierResponse::unknown('not_attempted', null, 0);

        for ($try = 1; $try <= $maxTries; $try++) {
            // The first request was paid for by the caller; every retry is another
            // request the supplier has to serve, so it needs its own allowance.
            if ($try > 1 && ! $this->rateLimiter->tryAcquire($attempt->supplier)) {
                DeliveryLog::info('supplier.retry_skipped_rate_limit', [
                    'item_id' => $item->public_id,
                    'supplier' => $attempt->supplier->value,
                    'request_id' => $attempt->request_id,
                ]);

                return $response;
            }

            $attempt->tries = $try;

            $response = $this->client->issue(
                $attempt->supplier,
                $attempt->request_id,
                $item->sku,
                $item->public_id,
            );

            $attempt->applyResponse($response);

            DeliveryLog::info('supplier.call_finished', [
                'item_id' => $item->public_id,
                'supplier' => $attempt->supplier->value,
                'request_id' => $attempt->request_id,
                'attempt_no' => $attempt->attempt_no,
                'try' => $try,
                'outcome' => $response->outcome->value,
                'reason' => $response->reason,
                'http_status' => $response->httpStatus,
                'latency_ms' => $response->latencyMs,
            ]);

            if ($attempt->status !== AttemptStatus::Unknown) {
                return $response;
            }

            if ($try < $maxTries) {
                usleep($this->backoffMicroseconds($try));
            }
        }

        return $response;
    }

    /**
     * Exponential backoff with jitter.
     *
     * Jitter prevents items affected by the same outage from retrying together
     * and overwhelming the supplier during recovery.
     */
    private function backoffMicroseconds(int $try): int
    {
        $base = (int) config('ggsell.suppliers.backoff.base_ms');
        $cap = (int) config('ggsell.suppliers.backoff.max_ms');

        $delay = min($base * (2 ** ($try - 1)), $cap);
        $jittered = random_int((int) ($delay * 0.5), $delay);

        return $jittered * 1000;
    }

    /**
     * Starts a new supplier request.
     *
     * The operation runs while holding the item row lock. Without it, concurrent
     * runs could compute the same attempt number, create distinct request IDs, and
     * consume two keys. Job uniqueness narrows this race but does not close it.
     *
     * @return DeliveryAttempt|null Null when no work remains for this run.
     */
    private function openAttempt(OrderItem $item, SupplierId $supplier): ?DeliveryAttempt
    {
        return DB::transaction(function () use ($item, $supplier): ?DeliveryAttempt {
            Order::query()->lockForUpdate()->find($item->order_id);
            $locked = OrderItem::query()->lockForUpdate()->find($item->id);

            if ($locked === null || $locked->delivery()->exists()) {
                return null;
            }

            // An unresolved attempt means another run already contacted the
            // supplier, so a second request is unsafe.
            if ($locked->attempts()->unresolved()->exists()) {
                DeliveryLog::warning('fulfilment.concurrent_run_skipped', [
                    'item_id' => $item->public_id,
                    'supplier' => $supplier->value,
                ]);

                return null;
            }

            $attemptNo = (int) $locked->attempts()
                ->where('supplier', $supplier->value)
                ->max('attempt_no') + 1;

            // The row is written before the HTTP call so reconciliation retains the
            // request_id if the process stops while waiting for the supplier.
            return DeliveryAttempt::create([
                'order_id' => $locked->order_id,
                'order_item_id' => $locked->id,
                'supplier' => $supplier->value,
                'request_id' => DeliveryAttempt::makeRequestId($locked, $supplier, $attemptNo),
                'attempt_no' => $attemptNo,
                'status' => AttemptStatus::Pending,
                'started_at' => now(),
            ]);
        });
    }

    private function parkUnresolved(OrderItem $item, DeliveryAttempt $attempt): void
    {
        $this->settle($item, OrderItemStatus::DeliveryFailed, 'unresolved_attempt');

        DeliveryLog::error('fulfilment.parked_unresolved', [
            'item_id' => $item->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
            'attempt_no' => $attempt->attempt_no,
        ]);
    }

    /**
     * Records a non-delivering outcome for the item and refreshes the order.
     *
     * These states are recoverable, not final: the money is still owed to the
     * customer until either a later run delivers a code or settlement refunds it.
     */
    private function settle(OrderItem $item, OrderItemStatus $target, string $reason): void
    {
        DB::transaction(function () use ($item, $target, $reason): void {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $locked = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            $before = $locked->status;

            if ($locked->tryTransitionTo($target)) {
                $locked->failure_reason = $reason;
                $locked->save();
                $this->recordItemStatus($locked, $before);
            }

            $this->recalculateOrderStatus->handle($order);
        });

        DeliveryLog::error('fulfilment.failed', [
            'item_id' => $item->public_id,
            'status' => $target->value,
            'reason' => $reason,
        ]);
    }
}
