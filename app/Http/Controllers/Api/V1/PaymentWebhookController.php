<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Actions\ApplyPaymentToOrder;
use App\Domain\Payments\Actions\RecordPaymentEvent;
use App\Domain\Payments\PaymentOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentWebhookRequest;
use Illuminate\Http\JsonResponse;

/**
 * Accept a payment provider webhook.
 *
 * The controller is intentionally thin and performs two steps without wrapping
 * them in one transaction:
 *
 *   1. Record the event. A unique index rejects duplicates.
 *   2. Apply it while holding a lock on the order row.
 *
 * The separation is required because a constraint violation in step 1 would
 * abort a shared Postgres transaction and prevent step 2 from running.
 *
 * Return 200 once the event is accepted and stored, even when it cannot yet be
 * applied. A 5xx asks the provider to retry, which is unnecessary when the order
 * does not exist yet because the event is already safe for later processing.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(
        PaymentWebhookRequest $request,
        RecordPaymentEvent $recordPaymentEvent,
        ApplyPaymentToOrder $applyPaymentToOrder,
    ): JsonResponse {
        $recorded = $recordPaymentEvent->handle($request->toData());

        if (! $recorded->isFirstDelivery && $recorded->event->processed_at !== null) {
            // This event_id has already been received and processed.
            return response()->json([
                'received' => true,
                'outcome' => PaymentOutcome::Duplicate->value,
            ]);
        }

        // The event is recorded but unapplied because the order was missing or
        // processing failed. A redelivery is an opportunity to finish; treating
        // it as a duplicate could lose the payment permanently.

        $outcome = $applyPaymentToOrder->handle($recorded->event);

        return response()->json([
            'received' => true,
            'outcome' => $outcome->value,
        ]);
    }
}
