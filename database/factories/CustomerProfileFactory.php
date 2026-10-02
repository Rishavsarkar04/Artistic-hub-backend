<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phone' => fake()->numerify('9#########'),
            'date_of_birth' => fake()->optional()->dateTimeBetween('-70 years', '-18 years'),
            'gender' => fake()->optional()->randomElement(Gender::cases()),
            'avatar_path' => null,
            'notes' => null,
        ];
    }
}
