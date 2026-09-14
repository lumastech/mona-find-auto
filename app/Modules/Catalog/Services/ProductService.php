<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Exceptions\ConditionNotAllowed;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writing a listing: its details, and the variants that carry its price.
 *
 * Two rules live here rather than in a controller, because both have to hold
 * however the listing arrives — web form, API, or a seeder.
 *
 * The condition is decided from the seller's type, not from the form. A car
 * breaker sells parts off scrapped vehicles, so their listings are Car
 * Breaker whatever the payload says — and a payload that says otherwise is
 * rejected rather than corrected in silence, because "Brand New" is a claim a
 * buyer would act on.
 *
 * A listing always ends up with at least one variant. A seller who only gave
 * a price gets one created for them, so the cart, the order line and the
 * ledger never have to branch on whether a listing happens to have options.
 */
class ProductService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $variants  Empty for a single-price listing.
     *
     * @throws ConditionNotAllowed
     */
    public function create(Seller $seller, array $attributes, array $variants = []): Product
    {
        $condition = $this->resolveCondition($seller, $attributes['condition'] ?? null);

        return DB::transaction(function () use ($seller, $attributes, $variants, $condition): Product {
            $product = new Product([
                ...$this->fillable($attributes),
                'seller_id' => $seller->getKey(),
                /* Set here, not left to the model event: a seeder may be running WithoutModelEvents. */
                'slug' => Product::uniqueSlugFor((string) ($attributes['name'] ?? '')),
                'condition' => $condition,
                /* Both defaults are deliberate: staff set the inspection flag, and nothing is published without review. */
                'inspection_status' => InspectionStatus::Uninspected,
                'status' => ListingStatus::Draft,
            ]);

            $product->save();

            $this->syncVariants($product, $variants, $attributes);

            return $product->load('variants');
        });
    }

    /**
     * Update a listing's details.
     *
     * The condition is re-derived rather than trusted, so a breaker who
     * changes their business type — or a payload that tries to — still ends
     * up correctly badged.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>|null  $variants  Null leaves the variants alone.
     *
     * @throws ConditionNotAllowed
     */
    public function update(Product $product, array $attributes, ?array $variants = null): Product
    {
        $condition = array_key_exists('condition', $attributes)
            ? $this->resolveCondition($product->seller, $attributes['condition'])
            : $product->condition;

        return DB::transaction(function () use ($product, $attributes, $variants, $condition): Product {
            $product->fill($this->fillable($attributes));
            $product->condition = $condition;
            $product->save();

            if ($variants !== null) {
                $this->syncVariants($product, $variants, $attributes);
            }

            return $product->load('variants');
        });
    }

    /**
     * Which condition this seller's listing must carry.
     *
     * @throws ConditionNotAllowed when the seller's type forces a different one
     */
    public function resolveCondition(Seller $seller, Condition|string|null $requested): Condition
    {
        $forced = Condition::forcedFor($seller->type);
        $requested = is_string($requested) ? Condition::tryFrom($requested) : $requested;

        if ($forced === null) {
            return $requested ?? Condition::Used;
        }

        if ($requested !== null && $requested !== $forced) {
            throw ConditionNotAllowed::forSellerType($seller->type, $requested, $forced);
        }

        return $forced;
    }

    /**
     * Bring a listing's variants in line with what was submitted.
     *
     * Variants missing from the payload are removed, those carrying an id are
     * updated in place, and the rest are created. Exactly one ends up default:
     * without that, a cart would have to guess which price it was quoting.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @param  array<string, mixed>  $attributes  Falls back to a top-level price/quantity for a single-variant listing.
     */
    public function syncVariants(Product $product, array $variants, array $attributes = []): void
    {
        $rows = $this->normaliseVariants($variants, $attributes, $product);

        $keptIds = [];

        foreach ($rows as $position => $row) {
            $variant = $row['id'] === null
                ? null
                : $product->variants()->whereKey($row['id'])->first();

            $payload = [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'price' => $row['price'],
                'quantity' => $row['quantity'],
                'is_default' => $position === 0,
                'position' => $position,
            ];

            $variant = $variant === null
                ? $product->variants()->create($payload)
                : tap($variant)->update($payload);

            $keptIds[] = $variant->getKey();
        }

        $product->variants()->whereKeyNot($keptIds)->delete();
        $product->unsetRelation('variants');
    }

    /**
     * Fill in what a seller left out, and put the default first.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @param  array<string, mixed>  $attributes
     * @return array<int, array{id: int|null, sku: string, name: string|null, price: Money, quantity: int}>
     */
    private function normaliseVariants(array $variants, array $attributes, Product $product): array
    {
        /*
         * A seller who never opened the options panel gave a price and a
         * quantity on the form itself. That is still a variant — it just has
         * no name of its own.
         */
        if ($variants === []) {
            $existing = $product->defaultVariant()->first();

            $variants = [[
                'price' => $attributes['price'] ?? $existing->price ?? Money::ofNgwee(0),
                'quantity' => $attributes['quantity'] ?? $existing->quantity ?? 0,
                'name' => null,
                'id' => $existing?->getKey(),
            ]];
        }

        return Collection::make($variants)
            /* An explicit default is honoured; otherwise the first row wins. */
            ->sortByDesc(static fn (array $row): bool => (bool) ($row['is_default'] ?? false))
            ->values()
            ->map(static fn (array $row): array => [
                'id' => $row['id'] ?? null,
                'sku' => filled($row['sku'] ?? null)
                    ? (string) $row['sku']
                    : ProductVariant::generateSku($product->name),
                'name' => $row['name'] ?? null,
                'price' => Money::from($row['price'] ?? 0),
                'quantity' => (int) ($row['quantity'] ?? 0),
            ])
            ->all();
    }

    /**
     * The listing columns a seller may write.
     *
     * Status, the inspection flag and the review fields are all absent by
     * design — those move through ListingModerationService and
     * ListingInspectionService, never through a form payload.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fillable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'name',
            'description',
            'category_id',
            'make_id',
            'vehicle_model_id',
            'year_from',
            'year_to',
            'sourcing',
            'part_number',
            'oem_number',
            'engine_size_cc',
            'engine_code',
            'fuel_type',
            'transmission',
            'drive_type',
            'body_type',
            'trim',
            'chassis_compatibility',
            'warranty_text',
            'delivery_available',
        ]));
    }
}
