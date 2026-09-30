<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * What an index answered: the listings on this page, and the facet counts
 * across every page.
 */
final readonly class ListingPage
{
    /**
     * @param  LengthAwarePaginator<int, Product>  $listings
     * @param  array<string, array<string, int>>  $facets  Keyed like Meilisearch's facet distribution.
     */
    public function __construct(
        public LengthAwarePaginator $listings,
        public array $facets,
    ) {}

    /**
     * The query string is re-applied so page two of a filtered search is
     * still the same filtered search.
     *
     * @param  array<int, Product>  $items
     * @param  array<string, array<string, int>>  $facets
     */
    public static function of(array $items, int $total, SearchCriteria $criteria, array $facets): self
    {
        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $criteria->perPage,
            $criteria->page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );

        return new self($paginator->appends($criteria->toQueryString()), $facets);
    }
}
