<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Delivery\Models\Delivery;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Exceptions\IllegalTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * One deliverable unit of an order.
 *
 * This is the unit of everything that must happen exactly once in stage 2: one
 * supplier chain, one code, one delivery row, one refund, one pair of ledger
 * entries.
 *
 * @property int $id
 * @property int $order_id
 * @property string $public_id
 * @property string $sku
 * @property int $amount_minor
 * @property string $currency
 * @property OrderItemStatus $status
 * @property int $fulfilment_runs
 */
class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderItemStatus::class,
            'amount_minor' => 'integer',
            'position' => 'integer',
            'fulfilment_runs' => 'integer',
            'delivered_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * Public identifier. Item-level operations cross process boundaries, so the
     * supplier and the payment gateway must not see internal sequential IDs.
     */
    public static function newPublicId(): string
    {
        return 'itm_'.Str::lower((string) Str::ulid());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /**
     * The only way to change an item status.
     *
     * Does not persist. The caller must hold the transaction and row lock, making
     * it explicit that a status change requires FOR UPDATE.
     */
    public function transitionTo(OrderItemStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw IllegalTransition::betweenItemStates($this, $this->status, $target);
        }

        $this->status = $target;
    }

    /** Attempts a transition that may be skipped when it is no longer needed. */
    public function tryTransitionTo(OrderItemStatus $target): bool
    {
        if (! $this->status->canTransitionTo($target)) {
            return false;
        }

        $this->status = $target;

        return true;
    }

    /**
     * Whether this line's money may be given back.
     *
     * Normally only a line whose delivery concluded in failure qualifies. The
     * second case is a line stranded in flight with its retry budget spent: a
     * process killed on its last allowed run leaves one behind, and without this
     * it would never be delivered, never refunded, and its order never terminal.
     *
     * SettleUnfulfillableItems expresses the same rule in SQL to find candidates;
     * this is the one that decides, because it runs when the money actually moves.
     */
    public function isRefundable(int $maxFulfilmentRuns): bool
    {
        return $this->status->isRecoverable()
            || ($this->status->isInFlight() && $this->fulfilment_runs >= $maxFulfilmentRuns);
    }

    /** @param  Builder<self>  $query */
    public function scopeUnsettled(Builder $query): void
    {
        $query->whereNotIn('status', [
            OrderItemStatus::Delivered->value,
            OrderItemStatus::Refunded->value,
        ]);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
