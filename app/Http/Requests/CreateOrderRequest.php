<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Accepts both the stage-1 single-SKU body and the stage-2 multi-item body.
 *
 * The old shape is not deprecated noise: it is the majority of real traffic for
 * a storefront selling one key at a time, and keeping it means stage-1 clients
 * and documented examples keep working unchanged.
 */
class CreateOrderRequest extends FormRequest
{
    /** Keeps one payment settleable; the domain enforces the unit ceiling. */
    private const MAX_LINES = 20;

    public function rules(): array
    {
        // Require a sellable product, not merely an existing one, so a
        // disabled product is rejected cleanly before reaching the domain.
        $sellable = fn (): Exists => Rule::exists('products', 'sku')->where('is_active', true);

        return [
            'sku' => ['required_without:items', 'string', 'max:64', $sellable()],

            // prohibits keeps the request unambiguous: two shapes describing
            // different orders in one body would have to be silently merged.
            'items' => ['required_without:sku', 'prohibits:sku', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'items.*.sku' => ['required', 'string', 'max:64', $sellable()],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10'],

            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * Normalizes both accepted shapes into the single form the domain takes.
     *
     * @return list<array{sku: string, quantity: int}>
     */
    public function lines(): array
    {
        if ($this->has('items')) {
            return array_values(array_map(
                static fn (array $item): array => [
                    'sku' => (string) $item['sku'],
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ],
                $this->validated('items'),
            ));
        }

        return [['sku' => $this->string('sku')->toString(), 'quantity' => 1]];
    }
}
