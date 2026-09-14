<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Listeners;

use App\Modules\Orders\Events\DisputeOpened;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Ratings\Jobs\RecomputeSellerTrustScore;

/**
 * A dispute is a quarter of a seller's trust score, so opening or settling
 * one moves it.
 *
 * Ratings listens to the disputes side of Orders rather than Orders knowing
 * anything about trust — the dependency runs one way, through the two events
 * Orders already publishes.
 */
class RecomputeTrustOnDisputeChange
{
    public function handle(DisputeOpened|DisputeResolved $event): void
    {
        $seller = $event->dispute->order->seller;

        RecomputeSellerTrustScore::dispatch($seller);
    }
}
