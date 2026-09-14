<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Resources;

use App\Modules\Catalog\Models\Product;
use App\Modules\Shopping\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quote request and its answer, for both sides of it.
 *
 * `is_acceptable` is computed rather than read off the status, because a
 * quote can be `quoted` in the database and dead in fact — expiry is the
 * passage of time, and the sweep only writes it down afterwards. A buyer
 * whose page says "Accept" on an expired quote is a buyer about to be
 * refused.
 *
 * @mixin Quotation
 */
class QuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'description' => $this->status->description(),
                'variant' => $this->status->badgeVariant(),
                'is_open' => $this->status->isOpen(),
            ],

            'quantity' => $this->quantity,
            'message' => $this->message,

            'quoted_unit_price_ngwee' => $this->quoted_unit_price_ngwee?->ngwee,
            'total_ngwee' => $this->quoted_unit_price_ngwee === null ? null : $this->total()->ngwee,
            'valid_until' => $this->valid_until?->toDateString(),
            'delivery_note' => $this->delivery_note,
            'decline_reason' => $this->decline_reason,

            /* Time, not status: an unswept stale quote must not offer a button. */
            'has_expired' => $this->hasExpired(),
            'is_acceptable' => $this->isAcceptable(),

            'requested_at' => $this->created_at?->toIso8601String(),
            'quoted_at' => $this->quoted_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),

            'listing' => [
                'id' => $this->product_id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'thumbnail_url' => $this->product->getFirstMediaUrl(Product::PHOTOS_COLLECTION, 'thumb') ?: null,
                'current_price_ngwee' => $this->variant->price->ngwee,
                'variant_id' => $this->product_variant_id,
                'variant_name' => $this->variant->name,
            ],

            'seller' => $this->whenLoaded('seller', fn (): array => [
                'id' => $this->seller->getKey(),
                'slug' => $this->seller->slug,
                'business_name' => $this->seller->business_name,
                'verified' => $this->seller->isVerified(),
            ]),

            /* The seller's inbox needs to know who is asking. */
            'buyer' => $this->whenLoaded('buyer', fn (): array => [
                'id' => $this->buyer->getKey(),
                'name' => $this->buyer->name,
            ]),
        ];
    }
}
