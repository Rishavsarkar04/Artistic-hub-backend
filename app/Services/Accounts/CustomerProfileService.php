<?php

namespace App\Services\Accounts;

use App\Data\CustomerProfileData;
use App\Exceptions\Accounts\ProfileAlreadyExists;
use App\Exceptions\Accounts\ProfileRequired;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CustomerProfileService
{
    /**
     * Creates the customer's one profile and sets their name, together. Repeated or concurrent
     * submissions never create a second profile: the user row is locked, and the unique
     * `customer_profiles.user_id` index backs that up.
     *
     * @throws ProfileAlreadyExists
     */
    public function createProfile(User $customer, CustomerProfileData $data): CustomerProfile
    {
        if (! $customer->isCustomer()) {
            throw new LogicException('Only customers have a customer profile.');
        }

        try {
            return DB::transaction(function () use ($customer, $data) {
                User::whereKey($customer->getKey())->lockForUpdate()->first();

                if ($customer->customerProfile()->exists()) {
                    throw new ProfileAlreadyExists;
                }

                $customer->name = $data->name;
                $customer->save();

                // Through the relation: user_id is set for us, and chaperone() links $profile->user back.
                $profile = $customer->customerProfile()->create($this->profileAttributes($data));
                $customer->setRelation('customerProfile', $profile);

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ProfileAlreadyExists;
        }
    }

    /** @throws ProfileRequired */
    public function updateProfile(User $customer, CustomerProfileData $data): CustomerProfile
    {
        $profile = $this->requireProfile($customer);

        return DB::transaction(function () use ($customer, $profile, $data) {
            $customer->name = $data->name;
            $customer->save();

            $profile->fill($this->profileAttributes($data))->save();

            return $profile;
        });
    }

    /** @throws ProfileRequired */
    public function requireProfile(User $customer): CustomerProfile
    {
        return $customer->customerProfile ?? throw new ProfileRequired;
    }

    /** @return array<string, mixed> */
    private function profileAttributes(CustomerProfileData $data): array
    {
        return [
            'phone' => $data->phone,
            'date_of_birth' => $data->dateOfBirth,
            'gender' => $data->gender,
        ];
    }
}
