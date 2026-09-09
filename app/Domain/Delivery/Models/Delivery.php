<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A completed delivery.
 *
 * UNIQUE(order_item_id) guarantees one delivery per deliverable unit, and
 * UNIQUE(code) is global, so a code can never be assigned to a second item even
 * when the supplier issues it twice.
 */
class Delivery extends Model
{
    protected $table = 'deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supplier' => SupplierId::class,
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttempt::class, 'delivery_attempt_id');
    }
}
