<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Models\Order;
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
    ) {}

    public function handle(DeliveryAttempt $attempt, Order $order): SupplierResponse
    {
        DeliveryLog::info('attempt.reconcile_started', [
            'order_id' => $order->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
            'attempt_no' => $attempt->attempt_no,
            'previous_status' => $attempt->status->value,
        ]);

        $response = $this->client->issue(
            $attempt->supplier,
            $attempt->request_id,
            $order->sku,
            $order->public_id,
        );

        $attempt->applyResponse($response, isReconciliation: true);

        DeliveryLog::info('attempt.reconcile_finished', [
            'order_id' => $order->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
            'outcome' => $response->outcome->value,
            'reason' => $response->reason,
            'latency_ms' => $response->latencyMs,
        ]);

        return $response;
    }
}
