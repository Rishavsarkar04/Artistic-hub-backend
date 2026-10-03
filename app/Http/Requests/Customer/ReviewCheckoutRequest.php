<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCheckoutRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The chosen address's reference_id. */
            'address_id' => ['required', 'string', 'ulid'],
        ];
    }
}
