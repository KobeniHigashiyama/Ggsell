<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Require a sellable product, not merely an existing one, so a
            // disabled product is rejected cleanly before reaching the domain.
            'sku' => [
                'required', 'string', 'max:64',
                Rule::exists('products', 'sku')->where('is_active', true),
            ],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
