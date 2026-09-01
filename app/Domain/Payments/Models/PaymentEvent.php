<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal of all incoming webhooks.
 *
 * Written before any business logic regardless of whether the event can be
 * applied. UNIQUE event_id provides deduplication, while the stored record is
 * the audit trail for explaining an order's state.
 *
 * @property string $event_id
 * @property string $outcome
 */
class PaymentEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
