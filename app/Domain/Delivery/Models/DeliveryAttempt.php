<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\SupplierOutcome;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attempt to obtain a code from a supplier.
 *
 * The row is created before the HTTP call. If the process stops after sending
 * the request but before receiving the response, the stored request_id lets a
 * background reconciliation determine the outcome.
 *
 * Attempts belong to an order item since stage 2, because each item runs its own
 * supplier chain.
 *
 * @property AttemptStatus $status
 * @property SupplierId $supplier
 * @property string $request_id
 */
class DeliveryAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'supplier' => SupplierId::class,
            'attempt_no' => 'integer',
            'tries' => 'integer',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'quarantined_at' => 'datetime',
            'verified_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Deterministic identifier for the delivery intent.
     *
     * attempt_no increments only after a definitive rejection. A timeout is
     * retried with the same request_id so the supplier cannot issue a second key.
     *
     * It is derived from the item, not the order: two items of one order must
     * never share a request_id or they would share a code.
     */
    public static function makeRequestId(OrderItem $item, SupplierId $supplier, int $attemptNo): string
    {
        return sprintf('req_%s_%s_%d', $item->public_id, $supplier->value, $attemptNo);
    }

    /**
     * Records the supplier response.
     *
     * finished_at is set only for a known outcome. An unknown attempt remains
     * unfinished, which is represented by both its status and a null timestamp.
     */
    public function applyResponse(SupplierResponse $response, bool $isReconciliation = false): void
    {
        $this->status = $this->resolveStatus($response, $isReconciliation);

        // A transport failure adds no new information and must not erase the last
        // meaningful supplier status.
        $this->http_status = $response->httpStatus ?? $this->http_status;
        // Once received, a code is preserved because it proves that a key was
        // issued. It is never replaced either: a supplier that answers the same
        // request_id with a different code is breaking its contract, and letting
        // the newer answer win would quietly hand the customer whatever it said
        // last. The original is what recovery and reconciliation act on.
        $this->code ??= $response->code;

        // What the supplier claimed the code is for, kept so a later run can
        // re-check provenance without asking again.
        $this->reported_sku ??= $response->sku;
        $this->error_reason = $response->reason;
        $this->latency_ms = $response->latencyMs;
        $this->finished_at = $this->status->isResolved() ? now() : null;

        $this->save();
    }

    /**
     * A rejection is definitive only when it comes from the supplier.
     *
     * A transport failure on the first send means the request did not reach the
     * supplier. The same failure during a retry or reconciliation only means the
     * outcome could not be checked. Marking that as failed would enable fallback
     * and could issue a second key for the same payment.
     *
     * An HTTP status proves that the supplier produced the response.
     *
     * A transport failure may close an attempt only on its first send in the live
     * request cycle. During reconciliation, both unknown and pending mean that the
     * original request may have reached the supplier and require the same caution.
     */
    private function resolveStatus(SupplierResponse $response, bool $isReconciliation): AttemptStatus
    {
        $incoming = match ($response->outcome) {
            SupplierOutcome::Ok => AttemptStatus::Succeeded,
            SupplierOutcome::Rejected => AttemptStatus::Failed,
            SupplierOutcome::Unknown => AttemptStatus::Unknown,
        };

        $transportFailure = $incoming === AttemptStatus::Failed && $response->httpStatus === null;
        $mustStayOpen = $isReconciliation || $this->status === AttemptStatus::Unknown;

        return $transportFailure && $mustStayOpen
            ? AttemptStatus::Unknown
            : $incoming;
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
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereIn('status', [AttemptStatus::Pending->value, AttemptStatus::Unknown->value]);
    }

    /**
     * Attempts holding a code this system is willing to deliver.
     *
     * A quarantined attempt is excluded: its code exists and was paid for, but it
     * belongs to someone else or to another product, so no recovery path may
     * treat it as this item's code.
     *
     * @param  Builder<self>  $query
     */
    public function scopeHoldingUsableCode(Builder $query): void
    {
        $query->where('status', AttemptStatus::Succeeded->value)
            ->whereNotNull('code')
            ->whereNull('quarantined_at');
    }
}
