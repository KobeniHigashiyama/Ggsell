<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Enums\ViolationKind;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\CircuitBreaker;
use App\Domain\History\Actions\RecordOrderEvent;
use App\Domain\History\Enums\OrderEventType;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Log\DeliveryLog;
use Illuminate\Support\Facades\DB;

/**
 * Refuses a code and records why.
 *
 * Three things happen together, and they have to be one transaction: the attempt
 * is marked so no recovery path picks the code up again, the incident is recorded
 * against the supplier, and the code itself is registered as inventory we are
 * holding but cannot use.
 *
 * The attempt keeps its own status. "The supplier answered ok with code X" stays
 * true; quarantined_at is our separate verdict on that answer, so the history
 * never has to be rewritten to make the present consistent.
 */
final readonly class QuarantineUntrustedCode
{
    public function __construct(
        private CircuitBreaker $breaker,
        private RecordOrderEvent $recordEvent,
    ) {}

    public function handle(OrderItem $item, DeliveryAttempt $attempt, string $code, ViolationKind $kind, ?string $detail = null): void
    {
        DB::transaction(function () use ($item, $attempt, $code, $kind, $detail): void {
            $attempt->quarantined_at ??= now();
            $attempt->save();

            // insertOrIgnore keeps one row per incident: a retry that re-detects
            // the same bad code must not inflate the supplier's record.
            DB::table('supplier_violations')->insertOrIgnore([
                'supplier' => $attempt->supplier->value,
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'delivery_attempt_id' => $attempt->id,
                'kind' => $kind->value,
                'code' => $code,
                'detail' => $detail,
                'detected_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // The supplier consumed a key for this code, so it is paid inventory
            // even though it can never be delivered. Reconciliation must see it.
            DB::table('orphaned_codes')->insertOrIgnore([
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'delivery_attempt_id' => $attempt->id,
                'supplier' => $attempt->supplier->value,
                'code' => $code,
                'reason' => $kind->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordEvent->handle(
                type: OrderEventType::SupplierViolation,
                orderId: $item->order_id,
                orderItemId: $item->id,
                payload: [
                    'supplier' => $attempt->supplier->value,
                    'kind' => $kind->value,
                    'detail' => $detail,
                    'request_id' => $attempt->request_id,
                ],
                refType: 'violation',
                refId: $attempt->id.':'.$kind->value,
            );
        });

        // A supplier that hands over unusable codes is failing, even though every
        // one of its responses was a 200. Feeding this to the breaker is what
        // eventually takes it out of the chain.
        $this->breaker->recordFailure($attempt->supplier);

        DeliveryLog::error('supplier.violation_detected', [
            'order_id' => $item->order_id,
            'item_id' => $item->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
            'kind' => $kind->value,
            'detail' => $detail,
        ]);
    }
}
