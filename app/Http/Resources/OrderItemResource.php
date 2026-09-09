<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItem */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->public_id,
            'position' => $this->position,
            'sku' => $this->sku,
            'amount' => $this->amount_minor / 100,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'supplier' => $this->supplier,
            'failure_reason' => $this->failure_reason,

            // Expose a code only after delivery is committed. A code stored in an
            // attempt does not yet belong to the customer.
            'code' => $this->when(
                $this->status === OrderItemStatus::Delivered && $this->relationLoaded('delivery'),
                fn () => $this->delivery?->code,
            ),

            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
        ];
    }
}
