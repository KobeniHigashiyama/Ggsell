<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\DTO\PaymentWebhookData;
use App\Domain\Payments\Models\PaymentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records an incoming webhook before any other processing.
 *
 * Deduplication relies on an attempted insert rather than a preliminary check.
 * Concurrent SELECT-then-INSERT requests can all observe an absent row, while
 * the unique index remains authoritative under concurrency.
 *
 * The insert uses its own transaction because a PostgreSQL constraint violation
 * aborts the current transaction. When nested, this becomes a savepoint, so only
 * the failed insert is rolled back and the caller's transaction stays usable.
 */
final readonly class RecordPaymentEvent
{
    public function handle(PaymentWebhookData $data): RecordedEvent
    {
        try {
            $event = DB::transaction(fn (): PaymentEvent => PaymentEvent::create([
                'event_id' => $data->eventId,
                'order_public_id' => $data->orderPublicId,
                'status' => $data->status,
                'amount_minor' => $data->amountMinor,
                'currency' => $data->currency,
                'payload' => $data->raw,
                'occurred_at' => $data->occurredAt,
                'received_at' => now(),
            ]));

            return new RecordedEvent($event, isFirstDelivery: true);
        } catch (UniqueConstraintViolationException) {
            // Repeated delivery is expected with at-least-once semantics. Return
            // success without changing state when this event already exists.
            $event = PaymentEvent::query()
                ->where('event_id', $data->eventId)
                ->sole();

            return new RecordedEvent($event, isFirstDelivery: false);
        }
    }
}
