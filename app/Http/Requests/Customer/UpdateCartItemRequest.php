<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The new quantity (not an amount to add). To remove the item, delete it instead of sending 0. */
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
