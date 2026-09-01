<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\CircuitBreaker;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\Log\DeliveryLog;
use Illuminate\Support\Facades\DB;

/**
 * Delivery orchestrator.
 *
 * The ordering of these steps is the core invariant of this class:
 *
 *   1. Lock the order and verify that delivery is required.
 *   2. Reconcile every unresolved attempt before making a new request.
 *   3. Only then proceed through the supplier chain.
 *
 * Reconciliation must precede new requests because a timed-out supplier may have
 * already issued a code. Starting a new request could issue a second key.
 *
 * The method is idempotent because webhooks, background recovery, and manual
 * recovery may all invoke it for an already delivered order.
 */
final readonly class FulfilOrder
{
    public function __construct(
        private SupplierClient $client,
        private CircuitBreaker $breaker,
        private CommitDelivery $commitDelivery,
        private ReconcileUnknownAttempt $reconcileAttempt,
    ) {}

    public function handle(int $orderId): void
    {
        $order = $this->claim($orderId);

        if ($order === null) {
            return;
        }

        if ($this->commitAlreadyIssuedCode($order)) {
            return;
        }

        if ($this->resolveOutstandingAttempts($order)) {
            return;
        }

        $this->walkSupplierChain($order);
    }

    /**
     * Acquires a row lock on the order.
     *
     * Returns null when delivery is unnecessary or impossible. Keeping every
     * eligibility check here gives workers, schedulers, and manual recovery the
     * same decision.
     */
    private function claim(int $orderId): ?Order
    {
        return DB::transaction(function () use ($orderId): ?Order {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if ($order === null) {
                return null;
            }

            if ($order->status->isTerminal()) {
                return null;
            }

            if (! $order->status->moneyReceived()) {
                DeliveryLog::warning('fulfilment.refused_unpaid', [
                    'order_id' => $order->public_id,
                    'order_status' => $order->status->value,
                ]);

                return null;
            }

            // Repair a stale status when the delivery row already exists.
            if ($order->delivery()->exists()) {
                if ($order->tryTransitionTo(OrderStatus::Delivered)) {
                    $order->delivered_at ??= now();
                    $order->save();
                }

                return null;
            }

            $maxRuns = (int) config('ggsell.recovery.max_fulfilment_runs');

            if ($order->fulfilment_runs >= $maxRuns) {
                // Exhausted orders require manual recovery and must stop consuming
                // supplier capacity and queue time.
                DeliveryLog::error('fulfilment.run_budget_exhausted', [
                    'order_id' => $order->public_id,
                    'runs' => $order->fulfilment_runs,
                ]);

                return null;
            }

            // Delivering means resuming an order whose previous run ended before
            // recording a final status, not performing another transition.
            if ($order->status !== OrderStatus::Delivering && ! $order->tryTransitionTo(OrderStatus::Delivering)) {
                return null;
            }

            // Every run consumes budget, including reconciliation-only runs. Otherwise
            // a long-lived unknown attempt could be retried forever without raising the
            // run_budget_exhausted operational signal. Manual recovery resets the count.
            $order->fulfilment_runs++;
            $order->save();

            return $order;
        });
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
     * @return bool True when delivery was committed and this run is complete.
     */
    private function commitAlreadyIssuedCode(Order $order): bool
    {
        $issued = $order->attempts()
            ->where('status', AttemptStatus::Succeeded->value)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first();

        if ($issued === null) {
            return false;
        }

        DeliveryLog::warning('fulfilment.recovered_uncommitted_code', [
            'order_id' => $order->public_id,
            'supplier' => $issued->supplier->value,
            'request_id' => $issued->request_id,
        ]);

        if ($this->commitDelivery->handle($order, $issued, (string) $issued->code)) {
            return true;
        }

        // If no delivery exists for this order, commit can fail only when
        // the code belongs to another order. Mark failure instead of leaving
        // the order in delivering and creating repeated orphan records.
        $this->settleFailed($order, 'code_belongs_to_another_order');

        return true;
    }

    /**
     * Step 3: reconcile attempts whose outcome is unknown.
     *
     * @return bool True when delivery completed or the outcome remains unknown,
     *              which prevents fallback to another supplier.
     */
    private function resolveOutstandingAttempts(Order $order): bool
    {
        $outstanding = $order->attempts()->unresolved()->orderBy('id')->get();

        foreach ($outstanding as $attempt) {
            $this->reconcileAttempt->handle($attempt, $order);

            // Use the persisted attempt status rather than the raw response because
            // a reconciliation transport failure cannot close an unresolved attempt.
            if ($attempt->status === AttemptStatus::Succeeded) {
                // The supplier issued the code that a new request could duplicate.
                $this->commitDelivery->handle($order, $attempt, (string) $attempt->code);

                return true;
            }

            if (! $attempt->status->allowsFallback()) {
                // The outcome remains unknown, so fallback is prohibited.
                $this->parkUnresolved($order, $attempt);

                return true;
            }

            // Failed confirms that no code was issued, allowing the next supplier.
        }

        return false;
    }

    private function walkSupplierChain(Order $order): void
    {
        $lastReason = null;

        foreach (SupplierId::chain() as $supplier) {
            if (! $this->breaker->allows($supplier)) {
                DeliveryLog::warning('supplier.skipped_breaker_open', [
                    'order_id' => $order->public_id,
                    'supplier' => $supplier->value,
                ]);

                $lastReason ??= 'breaker_open';

                continue;
            }

            $attempt = $this->openAttempt($order, $supplier);

            if ($attempt === null) {
                return;
            }

            $response = $this->askSupplier($order, $attempt);

            if ($attempt->status === AttemptStatus::Succeeded) {
                $this->breaker->recordSuccess($supplier);
                $this->commitDelivery->handle($order, $attempt, (string) $attempt->code);

                return;
            }

            // Empty stock is not a supplier health failure and must not affect
            // the circuit breaker.
            if (! $response->isOutOfStock()) {
                $this->breaker->recordFailure($supplier);
            }

            if (! $attempt->status->allowsFallback()) {
                // Retries are exhausted but the supplier may have issued a code, so
                // fallback remains unsafe. Background recovery will reconcile the order.
                $this->parkUnresolved($order, $attempt);

                return;
            }

            $lastReason = $response->reason;
        }

        $this->settleFailed($order, $lastReason);
    }

    /**
     * Retries within a single request_id.
     *
     * attempt_no and the attempt row remain unchanged. The supplier sees the same
     * request regardless of retry count, making retries after a timeout safe.
     */
    private function askSupplier(Order $order, DeliveryAttempt $attempt): SupplierResponse
    {
        $maxTries = (int) config('ggsell.suppliers.max_attempts');
        $response = SupplierResponse::unknown('not_attempted', null, 0);

        for ($try = 1; $try <= $maxTries; $try++) {
            $attempt->tries = $try;

            $response = $this->client->issue(
                $attempt->supplier,
                $attempt->request_id,
                $order->sku,
                $order->public_id,
            );

            $attempt->applyResponse($response);

            DeliveryLog::info('supplier.call_finished', [
                'order_id' => $order->public_id,
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
     * Jitter prevents orders affected by the same outage from retrying together
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
     * The operation runs while holding the order row lock. Without it, concurrent
     * runs could compute the same attempt number, create distinct request IDs, and
     * consume two keys. Job uniqueness narrows this race but does not close it.
     *
     * @return DeliveryAttempt|null Null when no work remains for this run.
     */
    private function openAttempt(Order $order, SupplierId $supplier): ?DeliveryAttempt
    {
        return DB::transaction(function () use ($order, $supplier): ?DeliveryAttempt {
            $locked = Order::query()->lockForUpdate()->find($order->id);

            if ($locked === null || $locked->delivery()->exists()) {
                return null;
            }

            // An unresolved attempt means another run already contacted the
            // supplier, so a second request is unsafe.
            $inFlight = $locked->attempts()->unresolved()->exists();

            if ($inFlight) {
                DeliveryLog::warning('fulfilment.concurrent_run_skipped', [
                    'order_id' => $order->public_id,
                    'supplier' => $supplier->value,
                ]);

                return null;
            }

            $attemptNo = (int) $locked->attempts()
                ->where('supplier', $supplier->value)
                ->max('attempt_no') + 1;

            return $this->createAttempt($locked, $supplier, $attemptNo);
        });
    }

    /**
     * The row is written before the HTTP call so reconciliation retains the
     * request_id if the process stops while waiting for the supplier.
     */
    private function createAttempt(Order $order, SupplierId $supplier, int $attemptNo): DeliveryAttempt
    {
        return DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => $supplier->value,
            'request_id' => DeliveryAttempt::makeRequestId($order, $supplier, $attemptNo),
            'attempt_no' => $attemptNo,
            'status' => AttemptStatus::Pending,
            'started_at' => now(),
        ]);
    }

    private function parkUnresolved(Order $order, DeliveryAttempt $attempt): void
    {
        DB::transaction(function () use ($order): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->tryTransitionTo(OrderStatus::DeliveryFailed)) {
                $locked->failure_reason = 'unresolved_attempt';
                $locked->save();
            }
        });

        DeliveryLog::error('fulfilment.parked_unresolved', [
            'order_id' => $order->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
            'attempt_no' => $attempt->attempt_no,
        ]);
    }

    private function settleFailed(Order $order, ?string $reason): void
    {
        // A shortage is resolved by replenishing inventory, not by repeatedly
        // querying the same suppliers.
        $target = $reason === 'out_of_stock'
            ? OrderStatus::OutOfStock
            : OrderStatus::DeliveryFailed;

        DB::transaction(function () use ($order, $target, $reason): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->tryTransitionTo($target)) {
                $locked->failure_reason = $reason ?? 'all_suppliers_failed';
                $locked->save();
            }
        });

        DeliveryLog::error('fulfilment.failed', [
            'order_id' => $order->public_id,
            'status' => $target->value,
            'reason' => $reason,
        ]);
    }
}
