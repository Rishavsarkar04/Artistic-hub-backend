<?php

namespace App\Data;

use App\Enums\Gender;
use Carbon\CarbonImmutable;

/** The fields a customer can set on their own profile. The name is stored on `users.name`. */
final readonly class CustomerProfileData
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?CarbonImmutable $dateOfBirth,
        public ?Gender $gender,
    ) {}
}
