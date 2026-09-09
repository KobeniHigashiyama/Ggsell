<?php

declare(strict_types=1);

namespace App\Domain\History\Models;

use App\Domain\History\Enums\OrderEventType;
use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One recorded fact about an order.
 *
 * The model is read-only by construction: the table refuses updates and deletes,
 * so anything that tries to edit history fails loudly instead of succeeding
 * quietly.
 *
 * @property OrderEventType $type
 * @property array<string, mixed> $payload
 */
class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => OrderEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeUpTo(Builder $query, \DateTimeInterface $moment): void
    {
        $query->where('occurred_at', '<=', $moment);
    }
}
