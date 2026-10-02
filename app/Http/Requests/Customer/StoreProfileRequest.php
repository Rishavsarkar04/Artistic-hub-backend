<?php

namespace App\Http\Requests\Customer;

use App\Data\CustomerProfileData;
use App\Enums\Gender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            // Accept "98765 43210" or "+91-98765-43210"; store digits with an optional leading +.
            $this->merge(['phone' => preg_replace('/[\s\-()]/', '', $this->input('phone'))]);
        }
    }

    /**
     * Name and phone are required; date of birth and gender are optional. Anything else sent
     * (email, role, status, notes) is ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            /** Digits, optionally starting with +; spaces, dashes and brackets are removed first. */
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
        ];
    }

    public function toData(): CustomerProfileData
    {
        return new CustomerProfileData(
            name: $this->validated('name'),
            phone: $this->validated('phone'),
            dateOfBirth: $this->validated('date_of_birth') ? CarbonImmutable::parse($this->validated('date_of_birth')) : null,
            gender: $this->validated('gender') ? Gender::from($this->validated('gender')) : null,
        );
    }
}
