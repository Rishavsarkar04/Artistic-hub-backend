<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->withRole(Role::Admin);
    }

    /** A customer who has just registered: no name and no profile yet. */
    public function customer(): static
    {
        return $this->withRole(Role::Customer)->state(['name' => null]);
    }

    /** A customer who has finished onboarding. */
    public function customerWithProfile(): static
    {
        return $this->withRole(Role::Customer)->has(CustomerProfile::factory(), 'customerProfile');
    }

    public function status(UserStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    private function withRole(Role $role): static
    {
        return $this->afterCreating(function (User $user) use ($role) {
            RoleModel::findOrCreate($role->value, Role::GUARD);
            $user->assignRole($role);
        });
    }
}
