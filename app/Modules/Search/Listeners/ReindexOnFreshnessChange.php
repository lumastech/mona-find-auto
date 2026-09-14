<?php

declare(strict_types=1);

namespace App\Modules\Search\Listeners;

use App\Modules\Inventory\Events\ProductFreshnessChanged;
use App\Modules\Search\Jobs\ReindexListing;

/**
 * Stock freshness moves listings up and down the rankings, and eventually off
 * the storefront entirely — and none of that goes through a model save.
 *
 * Inventory's nightly sweep updates freshness in bulk, so Scout's own
 * observer never sees it. Without this listener a listing hidden for going
 * fourteen days unconfirmed would stay in the index, findable, until the next
 * full rebuild.
 */
class ReindexOnFreshnessChange
{
    public function handle(ProductFreshnessChanged $event): void
    {
        ReindexListing::dispatch($event->product);
    }
}
