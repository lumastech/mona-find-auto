<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Movements come out as a seller adjustment, because that is the one reason
 * that carries no order and needs no set-up around it.
 *
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $before = fake()->numberBetween(1, 30);
        $change = fake()->numberBetween(-3, 5);

        return [
            'product_variant_id' => ProductVariant::factory(),
            'product_id' => fn (array $attributes): int => ProductVariant::query()->whereKey($attributes['product_variant_id'])->firstOrFail()->product_id,
            'seller_id' => fn (array $attributes): int => ProductVariant::query()->whereKey($attributes['product_variant_id'])->firstOrFail()->product->seller_id,
            'quantity_change' => $change,
            'quantity_before' => $before,
            'quantity_after' => max(0, $before + $change),
            'reason' => StockMovementReason::SellerAdjustment,
            'reference' => null,
            'order_id' => null,
            'note' => null,
            'actor_id' => null,
            'created_at' => now(),
        ];
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state([
            'product_variant_id' => $variant->getKey(),
            'product_id' => $variant->product_id,
            'seller_id' => $variant->product->seller_id,
        ]);
    }

    /**
     * A sale, with the order reference that makes it idempotent.
     */
    public function forOrder(int $orderId, string $reference, int $quantity = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'reason' => StockMovementReason::OrderPaid,
            'order_id' => $orderId,
            'reference' => $reference,
            'quantity_change' => -abs($quantity),
            'quantity_after' => max(0, $attributes['quantity_before'] - abs($quantity)),
        ]);
    }
}
