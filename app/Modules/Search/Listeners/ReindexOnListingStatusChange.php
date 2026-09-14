<?php

declare(strict_types=1);

namespace App\Modules\Search\Listeners;

use App\Modules\Catalog\Events\ListingStatusChanged;
use App\Modules\Search\Jobs\ReindexListing;

/**
 * Publishing, unpublishing, rejecting and archiving all decide whether a
 * listing may be found at all.
 *
 * Scout's model observer would catch most of these on its own, but not the
 * bulk unpublish that follows a seller being suspended — and a listing that
 * is findable after its shop was taken down is the failure mode worth
 * spending a listener on.
 */
class ReindexOnListingStatusChange
{
    public function handle(ListingStatusChanged $event): void
    {
        ReindexListing::dispatch($event->product);
    }
}
