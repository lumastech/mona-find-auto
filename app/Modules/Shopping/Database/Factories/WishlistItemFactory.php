<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Database\Factories;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shopping\Models\WishlistItem;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishlistItem>
 */
class WishlistItemFactory extends Factory
{
    protected $model = WishlistItem::class;

    /**
     * A save whose snapshot matches the listing as it stands — nothing has
     * changed yet. States below move one side or the other.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'price_ngwee_at_save' => fn (array $attributes): ?int => $this->currentPrice($attributes)?->ngwee,
            'in_stock_at_save' => true,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state([
            'product_id' => $product->getKey(),
            'price_ngwee_at_save' => $product->fromPrice()?->ngwee,
            'in_stock_at_save' => $product->hasStock(),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->getKey()]);
    }

    /**
     * Saved when it cost more — so the listing now reads as a price drop.
     */
    public function savedAbove(Money $amount): static
    {
        return $this->state(fn (array $attributes): array => [
            'price_ngwee_at_save' => ($this->currentPrice($attributes) ?? Money::zero())->plus($amount)->ngwee,
        ]);
    }

    /**
     * Saved when it cost less — the listing has since gone up.
     */
    public function savedBelow(Money $amount): static
    {
        return $this->state(fn (array $attributes): array => [
            'price_ngwee_at_save' => ($this->currentPrice($attributes) ?? Money::zero())->minus($amount)->ngwee,
        ]);
    }

    /**
     * Saved while the shelf still had stock.
     */
    public function savedInStock(): static
    {
        return $this->state(['in_stock_at_save' => true]);
    }

    public function savedOutOfStock(): static
    {
        return $this->state(['in_stock_at_save' => false]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function currentPrice(array $attributes): ?Money
    {
        $productId = $attributes['product_id'] ?? null;

        if (! is_int($productId)) {
            return null;
        }

        return Product::query()->with('variants')->find($productId)?->fromPrice();
    }
}
