<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controllers\Api;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Http\Requests\SearchRequest;
use App\Modules\Search\Http\Resources\SearchHitResource;
use App\Modules\Search\Services\FacetPresenter;
use App\Modules\Search\Services\ProductSearch;
use App\Modules\Search\Services\SearchAnalytics;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/search — the same search the storefront runs.
 *
 * Same parameters, same ranking, same tiers, same facet counts. The mobile
 * app has to be able to reproduce any result a buyer could reach in the
 * browser, and the surest way to guarantee that is for both to go through
 * one request object and one service.
 *
 * Public, like the storefront page.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly ProductSearch $search,
        private readonly FacetPresenter $facets,
        private readonly SearchAnalytics $analytics,
    ) {}

    public function __invoke(SearchRequest $request): JsonResponse
    {
        $criteria = $request->criteria();
        $results = $this->search->search($criteria);

        $this->analytics->record($criteria, $results, $request->user());

        $paginator = $results->listings->through(
            fn (Product $product): array => SearchHitResource::make($product, $results->tierFor($product))->resolve($request),
        );

        return ApiResponse::paginated($paginator, [
            'term' => $criteria->query,
            'sort' => $criteria->sort->value,
            'tier' => $results->tier->value,
            'notice' => $results->notice(),
            'filters' => $criteria->appliedFilters(),
            'facets' => $this->facets->present($results->facets),
            'fallback_category_id' => $results->fallbackCategory?->id,
        ]);
    }
}
