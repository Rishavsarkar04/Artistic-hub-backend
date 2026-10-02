<?php

namespace App\Services\Accounts;

use App\Data\CustomerProfileData;
use App\Enums\MediaDirectory;
use App\Exceptions\Accounts\ProfileAlreadyExists;
use App\Exceptions\Accounts\ProfileRequired;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Media\MediaStorageService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CustomerProfileService
{
    public function __construct(private MediaStorageService $mediaStorageService) {}

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

    /**
     * Stores the new avatar, points the profile at it, then deletes the old file. If saving the
     * profile fails, the new file is deleted again, so no orphan is left behind.
     *
     * @throws ProfileRequired
     */
    public function updateAvatar(User $customer, UploadedFile $avatar): CustomerProfile
    {
        $profile = $this->requireProfile($customer);
        $oldPath = $profile->avatar_path;
        $newPath = $this->mediaStorageService->store($avatar, MediaDirectory::Avatars);

        try {
            $profile->forceFill(['avatar_path' => $newPath])->save();
        } catch (\Throwable $e) {
            $this->mediaStorageService->delete($newPath);

            throw $e;
        }

        if ($oldPath !== null) {
            $this->mediaStorageService->delete($oldPath);
        }

        return $profile;
    }

    /** @throws ProfileRequired */
    public function removeAvatar(User $customer): CustomerProfile
    {
        $profile = $this->requireProfile($customer);
        $oldPath = $profile->avatar_path;

        if ($oldPath !== null) {
            $profile->forceFill(['avatar_path' => null])->save();
            $this->mediaStorageService->delete($oldPath);
        }

        return $profile;
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
