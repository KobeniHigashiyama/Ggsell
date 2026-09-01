<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A completed delivery. UNIQUE(order_id) guarantees one delivery per order, and
 * UNIQUE(code) guarantees that a code cannot be assigned to multiple orders.
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

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttempt::class, 'delivery_attempt_id');
    }
}
