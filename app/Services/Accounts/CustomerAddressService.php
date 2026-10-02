<?php

namespace App\Services\Accounts;

use App\Data\CustomerAddressData;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * A customer's saved addresses. Every lookup goes through the customer's own profile, so another
 * customer's address is simply "not found". Default changes lock the profile row first, which also
 * covers the moment before any address exists, so there is always exactly one default.
 */
final class CustomerAddressService
{
    /** @return Collection<int, CustomerAddress> Default first, then newest. */
    public function listAddresses(CustomerProfile $profile): Collection
    {
        return $profile->addresses()->orderByDesc('is_default')->latest('id')->get();
    }

    /** The first address becomes the default automatically. */
    public function createAddress(CustomerProfile $profile, CustomerAddressData $data): CustomerAddress
    {
        return DB::transaction(function () use ($profile, $data) {
            $this->lockProfile($profile);

            $address = new CustomerAddress($data->toAttributes());
            $address->customerProfile()->associate($profile);
            $address->is_default = ! $profile->addresses()->exists();
            $address->save();

            return $address;
        });
    }

    /** @throws ModelNotFoundException when the address is not one of this customer's */
    public function updateAddress(CustomerProfile $profile, int $addressId, CustomerAddressData $data): CustomerAddress
    {
        $address = $this->findAddress($profile, $addressId);
        $address->fill($data->toAttributes())->save();

        return $address;
    }

    /**
     * Makes this address the default and clears the previous one, atomically.
     *
     * @throws ModelNotFoundException when the address is not one of this customer's
     */
    public function setDefaultAddress(CustomerProfile $profile, int $addressId): CustomerAddress
    {
        return DB::transaction(function () use ($profile, $addressId) {
            $this->lockProfile($profile);

            $address = $this->findAddress($profile, $addressId);
            $profile->addresses()->whereKeyNot($address->getKey())->where('is_default', true)->update(['is_default' => false]);
            $address->is_default = true;
            $address->save();

            return $address;
        });
    }

    private function findAddress(CustomerProfile $profile, int $addressId): CustomerAddress
    {
        return $profile->addresses()->findOrFail($addressId);
    }

    private function lockProfile(CustomerProfile $profile): void
    {
        CustomerProfile::whereKey($profile->getKey())->lockForUpdate()->first();
    }
}
