<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Database\Factories;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Shopping\Models\Cart;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Models\Quotation;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * A line whose stored price matches the listing — nothing to report.
     *
     * The product and seller ids are read off the variant rather than made
     * up, because a line whose seller_id disagrees with its listing would
     * group into the wrong shop and quietly invalidate every cart test that
     * checks a subtotal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_id' => fn (array $attributes): int => $this->variant($attributes)->product_id,
            'seller_id' => fn (array $attributes): int => $this->variant($attributes)->product->seller_id,
            'quantity' => 1,
            'unit_price_ngwee' => fn (array $attributes): int => $this->variant($attributes)->price->ngwee,
            'quotation_id' => null,
        ];
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state([
            'product_variant_id' => $variant->getKey(),
            'product_id' => $variant->product_id,
            'seller_id' => $variant->product->seller_id,
            'unit_price_ngwee' => $variant->price->ngwee,
        ]);
    }

    public function inCart(Cart $cart): static
    {
        return $this->state(['cart_id' => $cart->getKey()]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(['quantity' => $quantity]);
    }

    /**
     * A line the buyer last saw at a different price — what the cart reports
     * as "price changed".
     */
    public function pricedAt(Money $price): static
    {
        return $this->state(['unit_price_ngwee' => $price->ngwee]);
    }

    /**
     * A line that came from an accepted quote, priced off the offer.
     */
    public function fromQuotation(Quotation $quotation): static
    {
        return $this->state([
            'quotation_id' => $quotation->getKey(),
            'product_variant_id' => $quotation->product_variant_id,
            'product_id' => $quotation->product_id,
            'seller_id' => $quotation->seller_id,
            'quantity' => $quotation->quantity,
            'unit_price_ngwee' => $quotation->effectiveUnitPrice()->ngwee,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function variant(array $attributes): ProductVariant
    {
        return ProductVariant::query()
            ->with('product')
            ->whereKey($attributes['product_variant_id'])
            ->firstOrFail();
    }
}
