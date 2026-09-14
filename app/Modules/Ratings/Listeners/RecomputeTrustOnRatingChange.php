<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Listeners;

use App\Modules\Ratings\Events\RatingModerated;
use App\Modules\Ratings\Events\RatingSubmitted;
use App\Modules\Ratings\Jobs\RecomputeSellerTrustScore;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Sellers\Models\Seller;

/**
 * A review landing on a shop, or being taken down, changes its score.
 *
 * Only ratings pointed AT a seller matter here — a shop's rating of a buyer
 * says nothing about the shop. And a moderation move only matters when it
 * crossed the line between counting and not counting: releasing a review from
 * the queue onto the page changes what buyers read but not what the shop is
 * scored on, so there is nothing to recompute.
 */
class RecomputeTrustOnRatingChange
{
    public function handle(RatingSubmitted|RatingModerated $event): void
    {
        if ($event instanceof RatingModerated && ! $event->changesAggregate()) {
            return;
        }

        $seller = $this->sellerFor($event->rating);

        if ($seller !== null) {
            RecomputeSellerTrustScore::dispatch($seller);
        }
    }

    private function sellerFor(Rating $rating): ?Seller
    {
        if ($rating->ratee_type !== (new Seller)->getMorphClass()) {
            return null;
        }

        return Seller::query()->find($rating->ratee_id);
    }
}
