<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this->public_id,
            'amount' => $this->amount_minor / 100,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'recoverable' => $this->status->isRecoverable(),
            'failure_reason' => $this->failure_reason,

            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
        ] + $this->singleItemFields();
    }

    /**
     * Stage-1 compatible top-level sku and code.
     *
     * A single-line order is still the common case, and stage-1 clients read
     * these two fields. They are omitted for a genuinely multi-item order rather
     * than filled with the first line, because a wrong code is worse than an
     * absent one.
     *
     * @return array{sku?: string, code?: string}
     */
    private function singleItemFields(): array
    {
        if (! $this->resource->relationLoaded('items')) {
            return [];
        }

        $items = $this->resource->items;

        if ($items->count() !== 1) {
            return [];
        }

        /** @var OrderItem $item */
        $item = $items->first();
        $fields = ['sku' => $item->sku];

        if ($item->relationLoaded('delivery') && $item->delivery !== null) {
            $fields['code'] = $item->delivery->code;
        }

        return $fields;
    }
}
