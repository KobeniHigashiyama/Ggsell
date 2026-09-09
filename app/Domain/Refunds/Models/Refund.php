<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Models;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Gateways\RefundOutcome;
use App\Domain\Refunds\Gateways\RefundResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money returned for one order item.
 *
 * The row is written before the gateway call, so a crash mid-refund leaves a
 * record with a request id that recovery can ask about instead of a silent gap.
 *
 * @property RefundStatus $status
 * @property int $amount_minor
 * @property string $refund_request_id
 */
class Refund extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'tries' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Deterministic idempotency key for the gateway.
     *
     * Derived from the item so every retry, from any process, asks for the same
     * refund rather than a second one.
     */
    public static function makeRequestId(OrderItem $item): string
    {
        return 'rfn_'.$item->public_id;
    }

    /**
     * Records the gateway response.
     *
     * A transport failure never downgrades a resolved status, and it never
     * becomes Failed: "we could not reach the gateway" does not prove that the
     * money stayed put.
     */
    public function applyResponse(RefundResponse $response): void
    {
        $this->status = match ($response->outcome) {
            RefundOutcome::Ok => RefundStatus::Succeeded,
            RefundOutcome::Rejected => RefundStatus::Failed,
            RefundOutcome::Unknown => RefundStatus::Unknown,
        };

        $this->gateway_reference = $response->reference ?? $this->gateway_reference;
        $this->failure_reason = $response->reason;
        $this->completed_at = $this->status->isResolved() ? now() : null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Unknown->value]);
    }
}
