<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing as its own seller sees it on the stock screen.
 *
 * Deliberately narrower than SellerProductResource: this screen is about
 * numbers and dates, and a seller walking their shelves with a phone should
 * not be sent the specification table and ten photo URLs per row.
 *
 * @mixin Product
 */
class StockListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'thumbnail_url' => $this->getFirstMediaUrl(Product::PHOTOS_COLLECTION, 'thumb') ?: null,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'live' => $this->status->isVisibleToBuyers(),
            ],

            'freshness' => [
                'value' => $this->freshness_state->value,
                'label' => $this->freshness_state->label(),
                'description' => $this->freshness_state->description(),
                'variant' => $this->freshness_state->badgeVariant(),
                'needs_confirmation' => $this->freshness_state->needsConfirmation(),
                'hidden' => ! $this->freshness_state->isVisibleToBuyers(),
                'confirmed_at' => $this->freshness_confirmed_at?->toIso8601String(),
                'days_since_confirmed' => $this->daysSinceStockConfirmed(),
            ],

            'variants' => StockVariantResource::collection($this->whenLoaded('variants'))->resolve($request),

            'total_quantity' => $this->relationLoaded('variants')
                ? $this->variants->sum(static fn (ProductVariant $variant): int => $variant->quantity)
                : null,
        ];
    }
}
