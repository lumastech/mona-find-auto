<?php

declare(strict_types=1);

namespace App\Modules\Search\Listeners;

use App\Modules\Ratings\Events\SellerTrustScoreChanged;
use App\Modules\Search\Jobs\ReindexSellerListings;

/**
 * A shop's rating and review count are two of the six components of every one
 * of its listings' quality scores, and those scores are computed at index
 * time.
 *
 * So a single new review changes where that shop's entire catalogue sits in
 * the results without one listing row being touched — which is exactly the
 * case Scout's model observer cannot see. Same shape, and same job, as a
 * seller being verified.
 *
 * Ratings only fires the event when a component ranking actually reads has
 * moved, so this does not re-index a catalogue because a trust score drifted
 * by a tenth of a point.
 */
class ReindexOnSellerTrustChange
{
    public function handle(SellerTrustScoreChanged $event): void
    {
        ReindexSellerListings::dispatch($event->seller);
    }
}
