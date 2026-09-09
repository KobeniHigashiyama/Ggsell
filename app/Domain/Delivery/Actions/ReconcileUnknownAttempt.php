<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierRateLimiter;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Log\DeliveryLog;

/**
 * Reconciles an unresolved delivery attempt.
 *
 * Queries the same supplier with the same request_id. The supplier contract
 * guarantees the same code, so this determines whether a key was issued without
 * creating a second delivery.
 *
 * The circuit breaker is intentionally bypassed because reconciliation resolves
 * an existing obligation instead of creating a new one.
 */
final readonly class ReconcileUnknownAttempt
{
    public function __construct(
        private SupplierClient $client,
        private SupplierRateLimiter $rateLimiter,
    ) {}

    public function handle(DeliveryAttempt $attempt, OrderItem $item): SupplierResponse
    {
        if (! $this->rateLimiter->tryAcquire($attempt->supplier)) {
            // Reconciliation is still a request the supplier has to serve. Without
            // allowance the outcome simply stays unknown, which is the safe state:
            // no fallback, no second key, and the next sweep asks again.
            DeliveryLog::info('attempt.reconcile_deferred_rate_limit', [
                'item_id' => $item->public_id,
                'request_id' => $attempt->request_id,
            ]);

            return SupplierResponse::unknown('rate_limited', null, 0);
        }

        DeliveryLog::info('attempt.reconcile_started', [
            'item_id' => $item->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
            'attempt_no' => $attempt->attempt_no,
            'previous_status' => $attempt->status->value,
        ]);

        $response = $this->client->issue(
            $attempt->supplier,
            $attempt->request_id,
            $item->sku,
            $item->public_id,
        );

        $attempt->applyResponse($response, isReconciliation: true);

        DeliveryLog::info('attempt.reconcile_finished', [
            'item_id' => $item->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
            'outcome' => $response->outcome->value,
            'reason' => $response->reason,
            'latency_ms' => $response->latencyMs,
        ]);

        return $response;
    }
}
