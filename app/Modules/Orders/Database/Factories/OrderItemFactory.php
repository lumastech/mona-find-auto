<?php

declare(strict_types=1);

namespace App\Modules\Orders\Database\Factories;

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unit = $this->faker->numberBetween(1_000, 200_000);
        $quantity = $this->faker->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_id' => fn (array $attributes): int => ProductVariant::query()
                ->whereKey($attributes['product_variant_id'])
                ->value('product_id') ?? 0,
            'product_name' => $this->faker->words(3, true),
            'variant_name' => $this->faker->randomElement([null, 'OEM', 'Aftermarket']),
            'sku' => strtoupper($this->faker->bothify('??-####')),
            'condition' => $this->faker->randomElement(Condition::cases()),
            'inspection_status' => InspectionStatus::Uninspected,
            'unit_price_ngwee' => $unit,
            'quantity' => $quantity,
            'total_ngwee' => $unit * $quantity,
        ];
    }

    /**
     * Build the line from a real variant, so the copied description matches
     * the listing it came from.
     */
    public function forVariant(ProductVariant $variant, int $quantity = 1): static
    {
        $variant->loadMissing('product');

        return $this->state([
            'product_variant_id' => $variant->getKey(),
            'product_id' => $variant->product_id,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'condition' => $variant->product->condition,
            'inspection_status' => $variant->product->inspection_status,
            'unit_price_ngwee' => $variant->price->ngwee,
            'quantity' => $quantity,
            'total_ngwee' => $variant->price->ngwee * $quantity,
        ]);
    }
}
