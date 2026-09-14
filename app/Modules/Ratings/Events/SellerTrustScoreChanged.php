<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Events;

use App\Modules\Ratings\Models\SellerTrustScore;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A seller's reputation moved.
 *
 * This is the event Search waits for. A seller's rating and review count are
 * two of the six components of every one of their listings' quality scores,
 * and those scores are computed at index time — so a new review changes where
 * a shop's whole catalogue sits in the results without a single listing row
 * being touched. Search listens and re-indexes the seller's listings.
 *
 * Fired only when a component that ranking actually reads has moved. A
 * recompute that lands on the same numbers is silence: re-indexing a
 * catalogue to write identical documents is the kind of work that looks free
 * until a shop has four thousand parts.
 */
class SellerTrustScoreChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Seller $seller,
        public SellerTrustScore $score,
    ) {}
}
