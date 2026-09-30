<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

use App\Modules\Search\Support\ListingPage;
use App\Modules\Search\Support\SearchCriteria;

/**
 * One page of listings for one set of criteria, from whichever index the
 * platform is running.
 *
 * Services\ProductSearch owns everything that does not depend on the engine
 * — the tier labels and the category fallback — and asks this for the rest.
 * Meilisearch is the real thing; the SQL implementation exists for hosting
 * that cannot run it, and is chosen by `scout.driver`.
 */
interface ListingSearch
{
    public function page(SearchCriteria $criteria): ListingPage;
}
