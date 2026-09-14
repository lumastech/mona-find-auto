<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Enums\SearchSort;
use App\Modules\Search\Http\Requests\SearchRequest;
use App\Modules\Search\Http\Resources\SearchHitResource;
use App\Modules\Search\Services\FacetPresenter;
use App\Modules\Search\Services\ProductSearch;
use App\Modules\Search\Services\SearchAnalytics;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Search\Support\SearchResults;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The storefront's search results page.
 *
 * Open to guests, like the rest of the storefront — a buyer should be able to
 * find out whether MonaFind has their part before being asked who they are.
 * The seller's phone number on the listing page is where that changes.
 *
 * Every search is logged before the response is sent, including the ones that
 * found nothing. The empty ones are the valuable ones: they are the list of
 * parts and vehicle names Zambian buyers expect the platform to know about
 * and it does not.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly ProductSearch $search,
        private readonly FacetPresenter $facets,
        private readonly SearchAnalytics $analytics,
    ) {}

    public function __invoke(SearchRequest $request): Response
    {
        $criteria = $request->criteria();
        $results = $this->search->search($criteria);

        $this->analytics->record($criteria, $results, $request->user());

        return Inertia::render('storefront/Search', [
            'term' => $criteria->query,
            'listings' => $this->listings($request, $results),
            'facets' => $this->facets->present($results->facets),
            'filters' => $criteria->appliedFilters(),

            /*
             * The sort control is on screen whatever the results look like.
             * A buyer who cannot find the cheapest option assumes there is
             * none.
             */
            'sort' => $criteria->sort->value,
            'sortOptions' => SearchSort::options(),

            'location' => $criteria->hasLocation()
                ? ['lat' => $criteria->latitude, 'lng' => $criteria->longitude, 'radius_km' => $criteria->radiusKm]
                : null,

            /* Tier 3: what the buyer is looking at instead of what they asked for. */
            'notice' => $results->notice(),
            'fallbackCategory' => $results->fallbackCategory === null ? null : [
                'id' => $results->fallbackCategory->id,
                'name' => $results->fallbackCategory->name,
                'slug' => $results->fallbackCategory->slug,
            ],

            'maxRadiusKm' => SearchCriteria::MAX_RADIUS_KM,
        ]);
    }

    /**
     * The paginator, with each listing carrying the tier it earned.
     *
     * @return array<string, mixed>
     */
    private function listings(SearchRequest $request, SearchResults $results): array
    {
        return $results->listings
            ->through(fn (Product $product): array => SearchHitResource::make($product, $results->tierFor($product))->resolve($request))
            ->toArray();
    }
}
