<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The reference_id of one of your saved addresses. */
            'address_id' => ['required', 'string', 'ulid'],
        ];
    }
}
