<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 999999);

        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['Small · 4 oz', 'Medium · 8 oz', 'Large · 12 oz']),
            'sku' => "EB-{$number}",
            'slug' => "variant-{$number}",
            'original_price' => '999.00',
            'selling_price' => '899.00',
            'stock' => 10,
            'description' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
