<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Resources;

use App\Modules\Catalog\Http\Resources\ProductCardResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Enums\MatchTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A search result: the ordinary listing card, plus why it is on the page.
 *
 * The card itself is Catalog's ProductCardResource, unchanged. A search
 * result is not a different kind of listing, and giving Search its own card
 * shape would leave two payloads and two Vue components to keep in step —
 * the next badge added to one would quietly be missing from the other.
 *
 * What Search adds is the match tier, which is the answer to "why am I seeing
 * this?" on a page of partial matches.
 *
 * @mixin Product
 */
class SearchHitResource extends JsonResource
{
    public function __construct(Product $product, private readonly MatchTier $tier)
    {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...ProductCardResource::make($this->resource)->resolve($request),

            'match' => [
                'tier' => $this->tier->value,
                'label' => $this->tier->label(),
                'explanation' => $this->tier->explanation(),
                'variant' => $this->tier->badgeVariant(),
            ],
        ];
    }
}
