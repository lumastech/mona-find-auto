<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing as it appears in a grid: search results, category pages, a
 * seller's shopfront.
 *
 * Both badges are always in the payload. A card that could render one without
 * the other is a card that will eventually render one without the other, so
 * the shape does not allow it — `condition` and `inspection` are siblings
 * here and siblings in the component that reads them.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $price = $this->fromPrice();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,

            /* The two badges. Independent attributes, always rendered together. */
            'condition' => [
                'value' => $this->condition->value,
                'label' => $this->condition->label(),
                'description' => $this->condition->description(),
                'variant' => $this->condition->badgeVariant(),
            ],
            'inspection' => [
                'value' => $this->inspection_status->value,
                'label' => $this->inspection_status->label(),
                'description' => $this->inspection_status->description(),
                'variant' => $this->inspection_status->badgeVariant(),
                'inspected' => $this->inspection_status->isInspected(),
            ],

            /* Integer ngwee, VAT-inclusive. The browser formats it; nothing divides it. */
            'price_ngwee' => $price?->ngwee,
            'has_multiple_variants' => $this->hasMultipleVariants(),
            'in_stock' => $this->hasStock(),

            /*
             * Availability and freshness, together. A buyer deciding whether
             * to cross town needs both: "In stock" from a shop that has not
             * confirmed anything in a week is a weaker claim than "Low stock"
             * from one that confirmed this morning, and a card that showed
             * only the first would be quietly overstating it.
             */
            'stock' => $this->stockSummary(),
            'freshness' => [
                'value' => $this->freshness_state->value,
                'label' => $this->freshness_state->storefrontLabel(),
                'description' => $this->freshness_state->description(),
                'variant' => $this->freshness_state->badgeVariant(),
                'confirmed_days_ago' => $this->daysSinceStockConfirmed(),
            ],

            'fitment' => $this->fitmentSummary(),
            'delivery_available' => $this->delivery_available,
            'thumbnail_url' => $this->thumbnailUrl(),

            'seller' => $this->whenLoaded('seller', fn (): array => [
                'id' => $this->seller->id,
                'slug' => $this->seller->slug,
                'business_name' => $this->seller->business_name,
                'verified' => $this->seller->isVerified(),
                'city' => $this->seller->relationLoaded('city') ? $this->seller->city->name : null,
            ]),

            'category' => $this->whenLoaded('category', fn (): array => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
        ];
    }

    /**
     * The listing's availability across all its options.
     *
     * A listing with three options is in stock if any of them is, and low if
     * the best it can manage is a low one. Never a number: "2 left" on a shop
     * whose counter sold one this morning is a more confident claim than the
     * platform can honestly make.
     *
     * @return array{value: string, label: string, variant: string, available: bool}
     */
    private function stockSummary(): array
    {
        $level = $this->variants
            ->map(static fn (ProductVariant $variant): StockLevel => $variant->stockLevel())
            ->sortBy(static fn (StockLevel $level): int => match ($level) {
                StockLevel::InStock => 0,
                StockLevel::LowStock => 1,
                StockLevel::OutOfStock => 2,
            })
            ->first() ?? StockLevel::OutOfStock;

        return [
            'value' => $level->value,
            'label' => $level->label(),
            'variant' => $level->badgeVariant(),
            'available' => $level->isAvailable(),
        ];
    }

    /**
     * The card image, or the smaller conversion if the card one has not been
     * generated yet. Null when the listing has no photo the browser may see.
     */
    private function thumbnailUrl(): ?string
    {
        $media = $this->getFirstMedia(Product::PHOTOS_COLLECTION);

        return $media === null
            ? null
            : Product::displayConversionUrl($media, 'card');
    }
}
