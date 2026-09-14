<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Resources;

use App\Models\User;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Support\SellerContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seller's public profile.
 *
 * The storefront page and /api/v1/sellers/{id} both answer through this, so
 * the contact-blur rule is applied once: a guest gets labels and a masked
 * shape, a logged-in buyer gets the real values, and neither surface can
 * quietly drift from the other.
 *
 * @mixin Seller
 */
class SellerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'business_name' => $this->business_name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'description' => $this->description,

            'verified' => $this->isVerified(),
            'verification_label' => $this->verification_status->publicLabel(),
            /* Set by the Catalog module; a breaker's stock is second-hand by definition. */
            'sells_breaker_stock' => $this->type->sellsBreakerStock(),

            'location' => [
                'province' => $this->province->name,
                'city' => $this->city->name,
                'street' => $this->street,
                'plot_number' => $this->plot_number,
                'single_line' => $this->singleLine(),
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ],

            /*
             * Never the real values for a guest. The masking happens on the
             * server because a CSS blur over the real number is not privacy:
             * it is in the response, and anybody can read the page source.
             */
            'contact' => SellerContact::for($this->resource, $viewer instanceof User ? $viewer : null)->toArray(),

            'opening_hours' => $this->opening_hours,
            'bay_count' => $this->bay_count,
            'logo_url' => $this->getFirstMediaUrl('logo') ?: null,

            'policies' => $this->whenLoaded(
                'currentPolicies',
                fn () => SellerPolicyResource::collection($this->currentPolicies)->resolve($request),
            ),

            /* Filled in by the Ratings module; the shape is fixed now so the page does not change later. */
            'rating' => [
                'average' => null,
                'count' => 0,
            ],

            'member_since' => $this->created_at?->toIso8601String(),
        ];
    }
}
