<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Shared by the customer and admin reset-password endpoints. */
class ResetPasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** From the reset link's `email` query parameter. */
            'email' => ['required', 'string', 'email', 'max:255'],
            /** From the reset link's `token` query parameter. */
            'token' => ['required', 'string'],
            /** Same rule as registration: at least 8 characters, with letters and numbers. */
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }
}
