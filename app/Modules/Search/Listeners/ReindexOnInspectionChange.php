<?php

declare(strict_types=1);

namespace App\Modules\Search\Listeners;

use App\Modules\Catalog\Events\ListingInspectionChanged;
use App\Modules\Search\Jobs\ReindexListing;

/**
 * The Inspected badge is worth 15 points of quality score and is a facet
 * buyers filter on, so a moderator setting it has to be visible in search
 * rather than at 2am tomorrow.
 */
class ReindexOnInspectionChange
{
    public function handle(ListingInspectionChanged $event): void
    {
        ReindexListing::dispatch($event->product);
    }
}
