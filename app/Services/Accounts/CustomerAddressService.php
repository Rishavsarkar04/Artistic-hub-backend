<?php

namespace App\Services\Accounts;

use App\Data\CustomerAddressData;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's saved addresses. Every lookup goes through the customer's own profile, so another
 * customer's address is simply "not found". Every change that can touch the default locks the
 * profile row first (which also covers the moment before any address exists), and all default
 * changes go through makeDefault(), so there is always exactly one default.
 */
final class CustomerAddressService
{
    /** @return Collection<int, CustomerAddress> Default first, then newest. */
    public function listAddresses(CustomerProfile $profile): Collection
    {
        return $profile->addresses()->orderByDesc('is_default')->latest('id')->get();
    }

    /** The first address always becomes the default; a later one does when $data->isDefault is true. */
    public function createAddress(CustomerProfile $profile, CustomerAddressData $data): CustomerAddress
    {
        return DB::transaction(function () use ($profile, $data) {
            $this->lockProfile($profile);

            $address = new CustomerAddress($data->toAttributes());
            $address->customerProfile()->associate($profile);
            $address->is_default = false;
            $address->save();

            if ($data->isDefault || $profile->addresses()->count() === 1) {
                $this->makeDefault($profile, $address);
            }

            return $address;
        });
    }

    /**
     * Updates the fields, and the default when $data->isDefault says so.
     *
     * @throws ModelNotFoundException when the address is not one of this customer's
     * @throws ValidationException when asked to un-default the current default
     */
    public function updateAddress(CustomerProfile $profile, int $addressId, CustomerAddressData $data): CustomerAddress
    {
        return DB::transaction(function () use ($profile, $addressId, $data) {
            $this->lockProfile($profile);

            $address = $this->findAddress($profile, $addressId);

            if ($data->isDefault === false && $address->is_default) {
                throw ValidationException::withMessages([
                    'is_default' => 'This is your default address. Make another address the default instead.',
                ]);
            }

            $address->fill($data->toAttributes())->save();

            if ($data->isDefault && ! $address->is_default) {
                $this->makeDefault($profile, $address);
            }

            return $address;
        });
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
            $this->makeDefault($profile, $address);

            return $address;
        });
    }

    /** Sets this address as the only default. Call inside a transaction, after lockProfile(). */
    private function makeDefault(CustomerProfile $profile, CustomerAddress $address): void
    {
        $profile->addresses()->whereKeyNot($address->getKey())->where('is_default', true)->update(['is_default' => false]);
        $address->is_default = true;
        $address->save();
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
