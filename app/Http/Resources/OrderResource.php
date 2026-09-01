<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this->public_id,
            'sku' => $this->sku,
            'amount' => $this->amount_minor / 100,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'recoverable' => $this->status->isRecoverable(),
            'failure_reason' => $this->failure_reason,

            // Expose a code only after delivery is committed. A code stored in an
            // attempt does not yet belong to the customer.
            'code' => $this->when(
                $this->status === OrderStatus::Delivered && $this->relationLoaded('delivery'),
                fn () => $this->delivery?->code,
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
