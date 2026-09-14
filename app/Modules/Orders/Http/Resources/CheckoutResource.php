<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Identity\Models\UserAddress;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Modules\Orders\Support\CheckoutSellerGroup;
use App\Modules\Orders\Support\CheckoutView;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\SellerPolicy;
use App\Modules\Shopping\Http\Resources\CartLineResource;
use App\Modules\Shopping\Support\CartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The whole checkout page in one payload.
 *
 * Every seller group carries its own fulfilment options, its own delivery
 * charge and its own four policies in full text — because the acceptance
 * modal is blocking and has to render the actual documents, not links to
 * them. A buyer who has to leave checkout to read a refund policy is a buyer
 * who does not read it.
 *
 * The policy id and version travel with each document and are posted back
 * with the order. That is what makes the acceptance record evidence: the
 * server checks that what the buyer agreed to is still what is current, and
 * refuses if the seller republished in the meantime.
 *
 * @mixin CheckoutView
 */
class CheckoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CheckoutView $view */
        $view = $this->resource;

        return [
            'groups' => array_map(
                fn (CheckoutSellerGroup $group): array => $this->group($request, $group),
                $view->groups,
            ),

            'items_total_ngwee' => $view->itemsTotal()->ngwee,
            'is_empty' => $view->isEmpty(),
            'blocks_checkout' => $view->blocksCheckout(),
            'seller_count' => $view->sellerCount(),

            'addresses' => $view->addresses->map(static fn (UserAddress $address): array => [
                'id' => $address->getKey(),
                'label' => $address->label,
                'recipient_name' => $address->recipient_name,
                'recipient_phone' => (string) $address->recipient_phone,
                'single_line' => $address->singleLine(),
                'directions' => $address->directions,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
                'is_default' => $address->is_default,
            ])->all(),
            'default_address_id' => $view->defaultAddress()?->getKey(),

            'payment_methods' => PaymentMethod::options(),

            /* MonaFind's half of what the buyer accepts. */
            'platform_terms' => $view->platformTerms->toArray(),
        ];
    }

    /**
     * One shop: its lines, its options, its terms.
     *
     * @return array<string, mixed>
     */
    private function group(Request $request, CheckoutSellerGroup $group): array
    {
        $seller = $group->seller;

        return [
            'seller' => [
                'id' => $seller->getKey(),
                'slug' => $seller->slug,
                'business_name' => $seller->business_name,
                'verified' => $seller->isVerified(),
                'address' => $seller->singleLine(),
                'phone' => $seller->phone,
                'latitude' => $seller->latitude,
                'longitude' => $seller->longitude,
                'place_id' => $seller->place_id,
                'opening_hours' => $seller->opening_hours,
                'delivery_note' => $seller->delivery_note,
            ],

            'lines' => array_map(
                static fn (CartLine $line): array => CartLineResource::make($line)->resolve($request),
                $group->cart->lines,
            ),

            'subtotal_ngwee' => $group->subtotal()->ngwee,
            'delivery_fee_ngwee' => $group->deliveryFee->ngwee,
            'delivery_fee_is_final' => $group->deliveryFeeIsFinal,

            'available_methods' => array_map(
                static fn (FulfilmentMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'description' => $method->description(),
                    'needs_address' => $method->needsAddress(),
                ],
                $group->availableMethods,
            ),
            'default_method' => $group->defaultMethod()->value,

            /*
             * Full text, not excerpts. The modal has to be readable without
             * leaving the page, and the version is what gets recorded.
             */
            'policies' => $group->policies
                ->map(static fn (SellerPolicy $policy): array => [
                    'policy_id' => $policy->getKey(),
                    'type' => $policy->type->value,
                    'label' => $policy->type->label(),
                    'version' => $policy->version,
                    'body' => $policy->body,
                    'effective_from' => $policy->effective_from->toIso8601String(),
                    'shows_platform_minimum' => $policy->type->showsPlatformMinimum(),
                ])
                ->values()
                ->all(),

            'missing_policies' => array_map(
                static fn (PolicyType $type): string => $type->label(),
                $seller->missingPolicies(),
            ),
        ];
    }
}
