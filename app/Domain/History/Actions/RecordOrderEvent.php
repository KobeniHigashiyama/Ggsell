<?php

declare(strict_types=1);

namespace App\Domain\History\Actions;

use App\Domain\History\Enums\OrderEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Appends one fact to the order log.
 *
 * The caller must already be inside the transaction that makes the fact true, so
 * an event cannot describe a change that rolled back, and a committed change
 * cannot be missing from history.
 *
 * Idempotent through (ref_type, ref_id, type), the same key shape the ledger
 * uses. Recovery replaying a step therefore replays no history.
 */
final readonly class RecordOrderEvent
{
    /**
     * Position of the next event of this kind in a subject's history.
     *
     * Used as the idempotency key for facts that can legitimately repeat, such as
     * a line moving in and out of delivery while it is retried. The caller must
     * already hold the row lock that serializes those changes, which every writer
     * of a status does.
     */
    public function nextSequence(OrderEventType $type, int $orderId, ?int $orderItemId = null): int
    {
        return DB::table('order_events')
            ->where('order_id', $orderId)
            ->where('type', $type->value)
            ->when($orderItemId !== null, fn ($query) => $query->where('order_item_id', $orderItemId))
            ->count() + 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return bool True when this call appended the event.
     */
    public function handle(
        OrderEventType $type,
        int $orderId,
        ?int $orderItemId,
        array $payload,
        string $refType,
        string $refId,
        ?Carbon $occurredAt = null,
    ): bool {
        return DB::table('order_events')->insertOrIgnore([
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'type' => $type->value,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'occurred_at' => $occurredAt ?? now(),
            'ref_type' => $refType,
            'ref_id' => $refId,
            'created_at' => now(),
        ]) > 0;
    }
}
