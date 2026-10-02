<?php

namespace App\Models;

use App\Enums\Role;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * An admin or a customer; the role comes from Spatie (`model_has_roles`), never a column.
 * `status` is not fillable: services set it explicitly so a request can never change it.
 * Soft deletes: a deleted user is hidden from queries (so cannot sign in) and keeps their email
 * reserved. Their customer profile is soft-deleted and restored with them. Only forceDelete()
 * removes the rows (the database cascades to the profile and addresses).
 * Signs in with Passport personal access tokens through the `api` guard (HasApiTokens).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, HasRoles, Notifiable, SoftDeletes;

    /** Spatie role guard name; see Role::GUARD for why it is pinned. */
    protected string $guard_name = Role::GUARD;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Keeps the customer profile in step with the user's soft delete. A model event is used (not a
     * service) so that every delete path, including Tinker and future code, stays consistent.
     */
    protected static function booted(): void
    {
        static::softDeleted(function (User $user) {
            $user->customerProfile?->delete();
        });

        static::restored(function (User $user) {
            $user->customerProfile()->onlyTrashed()->first()?->restore();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * chaperone(): a profile created or loaded through this relation gets `user` set to this same
     * User object, so `$profile->user` never needs another query or holds a stale copy.
     *
     * @return HasOne<CustomerProfile, $this>
     */
    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class)->chaperone();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin);
    }

    public function isCustomer(): bool
    {
        return $this->hasRole(Role::Customer);
    }

    /** A customer has finished onboarding once their profile exists. */
    public function hasCompletedProfile(): bool
    {
        return $this->customerProfile()->exists();
    }
}
