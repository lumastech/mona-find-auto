<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Resources;

use App\Modules\Catalog\Models\Product;
use App\Modules\Shopping\Enums\CartLineIssue;
use App\Modules\Shopping\Support\CartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cart line as the buyer sees it.
 *
 * Two prices go over the wire whenever one moved: what the line costs now,
 * and what it cost when they added it. The page needs both to say "was K450"
 * rather than just quietly showing a different number — which is the whole
 * point of storing the old one.
 *
 * @mixin CartLine
 */
class CartLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CartLine $line */
        $line = $this->resource;
        $item = $line->item;
        $variant = $item->variant;
        $product = $item->product;

        return [
            'id' => $item->getKey(),
            'quantity' => $line->quantity,
            'unit_price_ngwee' => $line->unitPrice->ngwee,
            'previous_unit_price_ngwee' => $line->previousUnitPrice?->ngwee,
            'requested_quantity' => $line->requestedQuantity,
            'line_total_ngwee' => $line->total()->ngwee,

            'product' => [
                'id' => $product->getKey(),
                'name' => $product->name,
                'slug' => $product->slug,
                'thumbnail_url' => $product->getFirstMediaUrl(Product::PHOTOS_COLLECTION, 'thumb') ?: null,
                'condition' => [
                    'value' => $product->condition->value,
                    'label' => $product->condition->label(),
                    'description' => $product->condition->description(),
                    'variant' => $product->condition->badgeVariant(),
                ],
                'inspection' => [
                    'value' => $product->inspection_status->value,
                    'label' => $product->inspection_status->label(),
                    'description' => $product->inspection_status->description(),
                    'variant' => $product->inspection_status->badgeVariant(),
                    'inspected' => $product->inspection_status->isInspected(),
                ],
            ],

            'variant' => [
                'id' => $variant->getKey(),
                'name' => $variant->name,
                'sku' => $variant->sku,
                /* Coarse, as everywhere on the storefront — never a raw count. */
                'level' => [
                    'value' => $variant->stockLevel()->value,
                    'label' => $variant->stockLevel()->label(),
                    'variant' => $variant->stockLevel()->badgeVariant(),
                    'available' => $variant->stockLevel()->isAvailable(),
                ],
                /*
                 * The ceiling for the quantity stepper. This is the one place
                 * a buyer sees the number, because a stepper that silently
                 * refuses to go past four is worse than one that says why.
                 */
                'max_quantity' => $variant->quantity,
            ],

            /* An accepted quote's line prices off the offer, not the shelf. */
            'quotation' => $item->quotation === null ? null : [
                'id' => $item->quotation->getKey(),
                'valid_until' => $item->quotation->valid_until?->toDateString(),
                'expired' => $item->quotation->hasExpired(),
            ],

            'issues' => array_map(
                static fn (CartLineIssue $issue): array => [
                    'value' => $issue->value,
                    'label' => $issue->label(),
                    'description' => $issue->description(),
                    'variant' => $issue->badgeVariant(),
                    'blocks_checkout' => $issue->blocksCheckout(),
                ],
                $line->issues,
            ),
        ];
    }
}
