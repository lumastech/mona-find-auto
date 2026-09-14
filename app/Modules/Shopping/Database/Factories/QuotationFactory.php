<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Database\Factories;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Models\Quotation;
use App\Support\Money\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    /**
     * An unanswered request.
     *
     * The seller is read off the listing rather than made up: a request whose
     * seller_id is not the listing's seller would be answerable by a shop
     * that does not own the part, which is precisely the thing the policy
     * exists to prevent and would make an authorisation test pass for the
     * wrong reason.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_id' => fn (array $attributes): int => $this->variant($attributes)->product_id,
            'seller_id' => fn (array $attributes): int => $this->variant($attributes)->product->seller_id,
            'status' => QuotationStatus::Open,
            'quantity' => fake()->numberBetween(2, 40),
            'message' => fake()->sentence(),
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

    public function forBuyer(User $buyer): static
    {
        return $this->state(['user_id' => $buyer->getKey()]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(['quantity' => $quantity]);
    }

    /**
     * Answered, and still good.
     */
    public function quoted(?Money $unitPrice = null, ?DateTimeInterface $validUntil = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuotationStatus::Quoted,
            'quoted_unit_price_ngwee' => ($unitPrice ?? $this->discountedPrice($attributes))->ngwee,
            'valid_until' => $validUntil ?? now()->addDays(7),
            'delivery_note' => 'Ready for collection in two working days.',
            'quoted_at' => now(),
        ]);
    }

    /**
     * Answered, but the day has passed. Still `quoted` in the column — which
     * is the point: expiry is time, and the sweep has not run yet.
     */
    public function stale(): static
    {
        return $this->quoted(validUntil: now()->subDay())->state([
            'quoted_at' => now()->subDays(8),
        ]);
    }

    public function accepted(): static
    {
        return $this->quoted()->state([
            'status' => QuotationStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state([
            'status' => QuotationStatus::Declined,
            'decline_reason' => 'We do not carry this part for that model.',
            'declined_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->quoted(validUntil: now()->subDays(2))->state([
            'status' => QuotationStatus::Expired,
            'expired_at' => now(),
        ]);
    }

    /**
     * A quote worth accepting: a tenth off the shelf price.
     *
     * multiplyByRatio rather than arithmetic on a float — shrinking an amount
     * always names its rounding.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function discountedPrice(array $attributes): Money
    {
        return $this->variant($attributes)->price->multiplyByRatio(9, 10);
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
