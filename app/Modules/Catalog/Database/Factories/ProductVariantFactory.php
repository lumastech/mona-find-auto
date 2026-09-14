<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-#####-???')),
            'name' => null,
            /* Kwacha, converted to ngwee by the cast — never a float. */
            'price' => Money::ofKwacha((string) fake()->numberBetween(50, 8000)),
            'quantity' => fake()->numberBetween(1, 25),
            'is_default' => false,
            'position' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true, 'position' => 0]);
    }

    public function priced(Money|int|string $price): static
    {
        return $this->state(['price' => Money::from($price)]);
    }

    public function outOfStock(): static
    {
        return $this->state(['quantity' => 0]);
    }

    public function named(string $name, int $position = 1): static
    {
        return $this->state(['name' => $name, 'position' => $position]);
    }
}
