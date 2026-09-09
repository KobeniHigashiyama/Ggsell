<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Delivery\Models\Delivery;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\IllegalTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A purchase. Since stage 2 it is a container of items rather than a single
 * product: it owns the payment, the total amount, and a status derived from its
 * items by RecalculateOrderStatus.
 *
 * @property int $id
 * @property string $public_id
 * @property OrderStatus $status
 * @property int $amount_minor
 * @property string $currency
 */
class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'settled_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /**
     * The only way to change an order status from the payment side.
     *
     * Does not persist the model. The caller must hold the transaction and row
     * lock, making it explicit that status changes require FOR UPDATE.
     *
     * Delivery-side statuses are not set here; they are derived from the items.
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
}
