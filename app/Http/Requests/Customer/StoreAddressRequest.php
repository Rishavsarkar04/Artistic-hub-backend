<?php

namespace App\Http\Requests\Customer;

use App\Data\CustomerAddressData;
use App\Enums\AddressLabel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAddressRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => preg_replace('/[\s\-()]/', '', $this->input('phone'))]);
        }
    }

    /**
     * The default flag is not set here: the first address becomes the default, and
     * PATCH /customer/addresses/{address}/default changes it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Defaults to home. */
            'label' => ['nullable', Rule::enum(AddressLabel::class)],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'country' => ['required', 'string', 'max:100'],
        ];
    }

    public function toData(): CustomerAddressData
    {
        return new CustomerAddressData(
            label: AddressLabel::tryFrom((string) $this->validated('label')) ?? AddressLabel::Home,
            recipientName: $this->validated('recipient_name'),
            phone: $this->validated('phone'),
            addressLine1: $this->validated('address_line_1'),
            addressLine2: $this->validated('address_line_2'),
            city: $this->validated('city'),
            state: $this->validated('state'),
            postalCode: $this->validated('postal_code'),
            country: $this->validated('country'),
        );
    }
}
