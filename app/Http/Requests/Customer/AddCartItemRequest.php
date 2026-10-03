<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The variant's reference_id. It must be buyable: active, of an active product, in stock. */
            'product_variant_id' => ['required', 'string', 'ulid'],
            /** Units to add (default 1); added to any already in the cart. The total cannot be more than the stock. */
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function quantity(): int
    {
        return (int) ($this->validated('quantity') ?? 1);
    }
}
