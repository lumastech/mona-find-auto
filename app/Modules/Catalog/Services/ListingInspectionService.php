<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Models\User;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Events\ListingInspectionChanged;
use App\Modules\Catalog\Models\Product;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The Inspected/Uninspected badge.
 *
 * The badge says MonaFind staff have physically looked at this part, so only
 * staff may set it and every change is audited with the reason given. It is
 * deliberately not part of ProductService: a seller editing their own listing
 * must not be able to reach it, however the payload is shaped.
 *
 * It is also independent of the condition badge. Marking a part Inspected
 * says nothing about whether it is new, used or off a breaker's yard, and the
 * storefront always renders both.
 */
class ListingInspectionService
{
    /**
     * @throws AuthorizationException when the actor is not MonaFind staff
     */
    public function markInspected(Product $product, User $actor, ?string $reason = null): Product
    {
        return $this->setStatus($product, InspectionStatus::Inspected, $actor, $reason);
    }

    /**
     * @throws AuthorizationException when the actor is not MonaFind staff
     */
    public function markUninspected(Product $product, User $actor, ?string $reason = null): Product
    {
        return $this->setStatus($product, InspectionStatus::Uninspected, $actor, $reason);
    }

    /**
     * Set the badge, audit it, and tell Search the ranking input changed.
     *
     * Setting it to what it already is does nothing at all: an audit trail
     * full of no-op rows is a trail nobody reads.
     *
     * @throws AuthorizationException when the actor is not MonaFind staff
     */
    public function setStatus(Product $product, InspectionStatus $status, User $actor, ?string $reason = null): Product
    {
        if (! $actor->can('inspect', $product)) {
            throw new AuthorizationException('Only MonaFind staff may set the inspection badge.');
        }

        $from = $product->inspection_status;

        if ($from === $status) {
            return $product;
        }

        $product->forceFill([
            'inspection_status' => $status,
            'inspected_at' => $status->isInspected() ? now() : null,
            'inspected_by' => $status->isInspected() ? $actor->getKey() : null,
        ])->save();

        audit(
            $actor,
            'listing.inspection.'.$status->value,
            $product,
            ['inspection_status' => $from->value],
            ['inspection_status' => $status->value],
            $reason,
        );

        ListingInspectionChanged::dispatch($product, $from, $status, $actor, $reason);

        return $product;
    }
}
