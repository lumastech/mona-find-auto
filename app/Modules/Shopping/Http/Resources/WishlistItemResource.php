<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Resources;

use App\Modules\Catalog\Http\Resources\ProductCardResource;
use App\Modules\Shopping\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved listing, plus what has happened to it since it was saved.
 *
 * The card is the ordinary storefront card — both badges, stock, freshness —
 * so a wishlist row and a search result say the same things about the same
 * part. What is added is the `change` block, which is the reason the buyer
 * came back to the page at all.
 *
 * @mixin WishlistItem
 */
class WishlistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'saved_at' => $this->created_at?->toIso8601String(),
            'listing' => ProductCardResource::make($this->product)->resolve($request),
            'change' => $this->change()->toArray(),
            /*
             * Whether "Move to cart" can do anything. A listing with nothing
             * on the shelf keeps its place on the list — the buyer wants to
             * know when it returns — but the button has to say so.
             */
            'purchasable' => $this->product->isVisibleToBuyers() && $this->product->hasStock(),
        ];
    }
}
