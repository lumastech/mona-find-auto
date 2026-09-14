<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Factories;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BackInStockSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackInStockSubscription>
 */
class BackInStockSubscriptionFactory extends Factory
{
    protected $model = BackInStockSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'product_id' => fn (array $attributes): int => ProductVariant::query()->whereKey($attributes['product_variant_id'])->firstOrFail()->product_id,
            'user_id' => User::factory(),
            'notified_at' => null,
        ];
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state([
            'product_variant_id' => $variant->getKey(),
            'product_id' => $variant->product_id,
        ]);
    }

    /**
     * A subscription that has already fired, and must never fire again.
     */
    public function notified(): static
    {
        return $this->state(['notified_at' => now()]);
    }
}
