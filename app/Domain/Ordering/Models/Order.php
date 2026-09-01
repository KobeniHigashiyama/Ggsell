<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Delivery\Models\Delivery;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\IllegalTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property OrderStatus $status
 * @property int $amount_minor
 */
class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount_minor' => 'integer',
            'quantity' => 'integer',
            'fulfilment_runs' => 'integer',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_payment_event_at' => 'datetime',
        ];
    }

    /**
     * Public identifier used by the payment contract. A ULID avoids exposing
     * business metrics and prevents enumeration of sequential order IDs.
     */
    public static function newPublicId(): string
    {
        return 'ord_'.Str::lower((string) Str::ulid());
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
     * The only way to change an order status.
     *
     * Does not persist the model. The caller must hold the transaction and row
     * lock, making it explicit that status transitions require FOR UPDATE.
     */
    public function transitionTo(OrderStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw IllegalTransition::between($this, $this->status, $target);
        }

        $this->status = $target;
    }

    /** Attempts a transition that may be skipped when it is no longer needed. */
    public function tryTransitionTo(OrderStatus $target): bool
    {
        if (! $this->status->canTransitionTo($target)) {
            return false;
        }

        $this->status = $target;

        return true;
    }

    /** @param  Builder<self>  $query */
    public function scopeAwaitingDelivery(Builder $query): void
    {
        $query->whereIn('status', [
            OrderStatus::Paid->value,
            OrderStatus::Delivering->value,
            OrderStatus::OutOfStock->value,
            OrderStatus::DeliveryFailed->value,
        ]);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
