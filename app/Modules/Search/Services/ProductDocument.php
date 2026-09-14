<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Contracts\SellerReputationProvider;
use App\Modules\Search\Support\SellerReputation;

/**
 * What one listing looks like inside Meilisearch.
 *
 * Everything the storefront filters, sorts or ranks on is flattened into this
 * array at index time. That includes facts that live on the seller — its
 * name, type, verification, town and GPS — because a facet sidebar cannot
 * join, and a buyer filtering "verified sellers in Kitwe" is asking one
 * question, not two.
 *
 * The consequence is that a change to a seller invalidates every listing it
 * owns, which is what Jobs\ReindexSellerListings exists for.
 */
final class ProductDocument
{
    /**
     * Reputations already looked up in this process, keyed by seller id.
     *
     * A full re-index walks a chunk of listings that mostly share a handful
     * of sellers; without this, the same shop's rating is fetched once per
     * part it stocks.
     *
     * @var array<int, SellerReputation>
     */
    private array $reputations = [];

    public function __construct(
        private readonly SellerReputationProvider $reputation,
        private readonly QualityScore $quality,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Product $product): array
    {
        $seller = $product->seller;
        $reputation = $this->reputationFor($product);
        $price = $product->fromPrice();

        return [
            'id' => $product->id,
            'slug' => $product->slug,

            /* Searched, in the order ProductIndex::searchableAttributes() declares. */
            'name' => $product->name,
            'part_number' => $product->part_number,
            'oem_number' => $product->oem_number,
            'make' => $product->make?->name,
            'vehicle_model' => $product->vehicleModel?->name,
            'category_name' => $product->category->name,
            'category_path' => $this->categoryPath($product->category),
            'seller_name' => $seller->business_name,
            'description' => $product->description,

            /* Fitment, as both a range and the individual years a filter hits. */
            'make_id' => $product->make_id,
            'vehicle_model_id' => $product->vehicle_model_id,
            'year_from' => $product->year_from,
            'year_to' => $product->year_to,
            'years' => $this->years($product),

            /*
             * The category and every heading above it, so filtering on
             * "Engine" returns the injectors filed three levels down.
             */
            'category_id' => $product->category_id,
            'category_ids' => [...$product->category->ancestorIds(), $product->category_id],

            /* The two independent badges. Neither implies the other. */
            'condition' => $product->condition->value,
            'inspected' => $product->isInspected(),

            'sourcing' => $product->sourcing->value,

            /*
             * Integer ngwee, VAT-inclusive, cheapest option — the figure the
             * card shows. Null for price-on-request, which sorts last in both
             * price directions rather than pretending to be free.
             */
            'price_ngwee' => $price?->ngwee,
            'in_stock' => $product->hasStock(),
            'delivery_available' => $product->delivery_available,

            'seller_id' => $product->seller_id,
            'seller_type' => $seller->type->value,
            'seller_verified' => $seller->isVerified(),
            'seller_rating' => $reputation->ratingAverage,
            'seller_review_count' => $reputation->reviewCount,

            'freshness_state' => $product->freshness_state->value,
            'dispute_band' => $reputation->band($this->quality->disputeThresholdPercent())->value,

            'province_id' => $seller->province_id,
            'province' => $seller->province->name,
            'city_id' => $seller->city_id,
            'city' => $seller->city->name,

            'quality_score' => $this->quality->for($product, $reputation),
            'published_at' => $product->published_at?->getTimestamp(),

            ...$this->geo($seller->coordinates()?->latitude, $seller->coordinates()?->longitude),
        ];
    }

    /**
     * The text a result is judged against when labelling its match tier.
     *
     * Narrower than what Meilisearch searches, on purpose. The category name,
     * the seller's name and the description are all searchable — they should
     * be, they are how a buyer who types a heading rather than a part still
     * lands somewhere — but none of them says anything about *this part*. A
     * brake disc filed under "Brake pads" would otherwise be labelled an
     * exact match for "hilux brake pads", which is the one label a buyer
     * acts on without reading further.
     *
     * So the tier is judged on what identifies the part: its name, its
     * numbers, and what it fits.
     */
    public function matchText(Product $product): string
    {
        return implode(' ', array_filter([
            $product->name,
            $product->part_number,
            $product->oem_number,
            $product->make?->name,
            $product->vehicleModel?->name,
            $product->yearRange(),
        ]));
    }

    /**
     * The relations every document needs, for eager loading on import.
     *
     * @return array<int, string>
     */
    public static function relations(): array
    {
        return ['seller.province', 'seller.city', 'category', 'make', 'vehicleModel', 'variants'];
    }

    /**
     * Forget the reputations memoised so far.
     *
     * A long-running queue worker would otherwise answer with a rating that
     * was true when the first job of its shift ran.
     */
    public function forgetReputations(): void
    {
        $this->reputations = [];
    }

    private function reputationFor(Product $product): SellerReputation
    {
        return $this->reputations[$product->seller_id]
            ??= $this->reputation->for($product->seller);
    }

    /**
     * "Engine / Fuel system / Injectors" — searchable, so a buyer typing the
     * heading rather than the part still lands somewhere.
     */
    private function categoryPath(Category $category): string
    {
        $names = Category::query()
            ->whereIn('id', [...$category->ancestorIds(), $category->getKey()])
            ->orderBy('depth')
            ->pluck('name')
            ->all();

        return implode(' / ', $names);
    }

    /**
     * Every model year the part fits, so "2010" filters without a range query.
     *
     * @return array<int, int>
     */
    private function years(Product $product): array
    {
        if ($product->year_from === null) {
            return [];
        }

        $to = max($product->year_to ?? $product->year_from, $product->year_from);

        return range($product->year_from, $to);
    }

    /**
     * Meilisearch's reserved geo field, present only when the shop has been
     * placed on the map. A document without `_geo` is simply absent from a
     * radius filter, which is the right answer for a shop nobody can find.
     *
     * @return array<string, array{lat: float, lng: float}>
     */
    private function geo(?float $latitude, ?float $longitude): array
    {
        if ($latitude === null || $longitude === null) {
            return [];
        }

        return ['_geo' => ['lat' => $latitude, 'lng' => $longitude]];
    }
}
