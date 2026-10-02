<?php

namespace App\Data;

use App\Enums\AddressLabel;

/**
 * The fields a customer can set on a saved address. Ownership is set by the service.
 * $isDefault: true = make this the default (others stop being default); false = keep it non-default
 * (refused for the current default); null = leave the default as it is.
 */
final readonly class CustomerAddressData
{
    public function __construct(
        public AddressLabel $label,
        public string $recipientName,
        public string $phone,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $state,
        public string $postalCode,
        public string $country,
        public ?bool $isDefault = null,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'label' => $this->label,
            'recipient_name' => $this->recipientName,
            'phone' => $this->phone,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
        ];
    }
}
