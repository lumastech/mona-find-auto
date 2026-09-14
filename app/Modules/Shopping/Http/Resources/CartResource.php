<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Resources;

use App\Modules\Shopping\Enums\CartLineIssue;
use App\Modules\Shopping\Support\CartLine;
use App\Modules\Shopping\Support\CartSellerGroup;
use App\Modules\Shopping\Support\CartView;
use App\Modules\Shopping\Support\RemovedCartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A whole cart: grouped by shop, with each shop's own subtotal.
 *
 * The shape is the argument. There is no flat list of lines anywhere in this
 * payload, because a MonaFind cart is not one purchase — it is one per shop,
 * each dispatched separately under that shop's own terms, and a UI handed a
 * flat list would eventually render it as though it were one delivery.
 *
 * @mixin CartView
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CartView $view */
        $view = $this->resource;

        return [
            'groups' => array_map(
                fn (CartSellerGroup $group): array => $this->group($request, $group),
                $view->groups,
            ),

            'total_ngwee' => $view->total()->ngwee,
            'unit_count' => $view->unitCount(),
            'line_count' => $view->lineCount(),
            'seller_count' => $view->sellerCount(),
            'is_empty' => $view->isEmpty(),

            /*
             * What changed on this read, and whether it stops the buyer
             * paying. A price rise does not: they have now been shown it.
             */
            'has_changes' => $view->hasChanges(),
            'blocks_checkout' => $view->blocksCheckout(),
            'issues' => array_map(
                static fn (CartLineIssue $issue): array => [
                    'value' => $issue->value,
                    'label' => $issue->label(),
                    'description' => $issue->description(),
                    'variant' => $issue->badgeVariant(),
                    'blocks_checkout' => $issue->blocksCheckout(),
                ],
                $view->issues(),
            ),

            /* Lines dropped during the check, so the page can say what went. */
            'removed' => array_map(
                static fn (RemovedCartLine $line): array => $line->toArray(),
                $view->removed,
            ),
        ];
    }

    /**
     * One shop's part of the cart.
     *
     * @return array<string, mixed>
     */
    private function group(Request $request, CartSellerGroup $group): array
    {
        return [
            'seller' => [
                'id' => $group->seller->getKey(),
                'slug' => $group->seller->slug,
                'business_name' => $group->seller->business_name,
                'verified' => $group->seller->isVerified(),
                'city' => $group->seller->relationLoaded('city') ? $group->seller->city->name : null,
            ],
            'lines' => array_map(
                static fn (CartLine $line): array => CartLineResource::make($line)->resolve($request),
                $group->lines,
            ),
            'subtotal_ngwee' => $group->subtotal()->ngwee,
            'unit_count' => $group->unitCount(),
            'has_issues' => $group->hasIssues(),
            'blocks_checkout' => $group->blocksCheckout(),
        ];
    }
}
