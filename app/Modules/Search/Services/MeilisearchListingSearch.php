<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Contracts\ListingSearch;
use App\Modules\Search\Support\ListingPage;
use App\Modules\Search\Support\ProductIndex;
use App\Modules\Search\Support\SearchCriteria;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Meilisearch\Endpoints\Indexes;

/**
 * One page of listings from Meilisearch.
 *
 * The ordering the brief asks for is not implemented here: it is the index's
 * ranking rules (see Support\ProductIndex). What is here is turning criteria
 * into Meilisearch parameters and hydrating what comes back.
 */
final class MeilisearchListingSearch implements ListingSearch
{
    /** Metres to a kilometre. Meilisearch's geo radius is in metres. */
    private const METRES_PER_KM = 1000;

    public function page(SearchCriteria $criteria): ListingPage
    {
        $facets = [];
        $filters = $this->filters($criteria);
        $sort = $criteria->sort->meilisearchSort($criteria->latitude, $criteria->longitude);

        /*
         * The callback form is Scout's documented way past its own parameter
         * building. Everything below is Meilisearch's own vocabulary: a geo
         * radius and a facet distribution have no Scout equivalent, and the
         * matching strategy is the difference between a search that finds
         * something and one that insists on every word.
         */
        $search = Product::search(
            $criteria->query ?? '',
            function (Indexes $index, string $query, array $options) use ($filters, $sort, &$facets): mixed {
                $options['filter'] = $filters;
                $options['sort'] = $sort;
                $options['facets'] = ProductIndex::facetAttributes();

                /*
                 * "last" lets Meilisearch drop trailing words until something
                 * matches, which is what produces tier 2 at all. The ranking
                 * rules then float the listings that needed no dropping back
                 * to the top.
                 */
                $options['matchingStrategy'] = 'last';

                $result = $index->search($query, $options);

                $facets = $result->getFacetDistribution();

                return $result;
            },
        );

        $page = $search
            ->query(fn (EloquentBuilder $query) => $query->with([...ProductDocument::relations(), 'media']))
            ->paginate($criteria->perPage, 'page', $criteria->page);

        /*
         * Re-wrapped rather than handed straight back. Scout's signature
         * promises only the paginator contract, and the pages above have to
         * map every listing through a resource — which is a concrete
         * paginator's job.
         */
        return ListingPage::of($page->items(), $page->total(), $criteria, $facets);
    }

    /**
     * The Meilisearch filter expression, as an array of AND-ed clauses.
     *
     * @return array<int, string>
     */
    public function filters(SearchCriteria $criteria): array
    {
        $filters = [];

        /*
         * `category_ids` carries the category and every heading above it, so
         * filtering on "Engine" returns the injectors filed three levels
         * down — the same promise the category pages make.
         */
        if ($criteria->categoryId !== null) {
            $filters[] = 'category_ids = '.$criteria->categoryId;
        }

        if ($criteria->makeId !== null) {
            $filters[] = 'make_id = '.$criteria->makeId;
        }

        if ($criteria->vehicleModelId !== null) {
            $filters[] = 'vehicle_model_id = '.$criteria->vehicleModelId;
        }

        /* Every year in the fitment range is indexed, so this is one lookup. */
        if ($criteria->year !== null) {
            $filters[] = 'years = '.$criteria->year;
        }

        if ($criteria->condition !== null) {
            $filters[] = 'condition = '.$this->quote($criteria->condition->value);
        }

        if ($criteria->inspectedOnly) {
            $filters[] = 'inspected = true';
        }

        if ($criteria->sourcing !== null) {
            $filters[] = 'sourcing = '.$this->quote($criteria->sourcing->value);
        }

        if ($criteria->minPriceNgwee !== null) {
            $filters[] = 'price_ngwee >= '.$criteria->minPriceNgwee;
        }

        if ($criteria->maxPriceNgwee !== null) {
            $filters[] = 'price_ngwee <= '.$criteria->maxPriceNgwee;
        }

        if ($criteria->sellerType !== null) {
            $filters[] = 'seller_type = '.$this->quote($criteria->sellerType->value);
        }

        if ($criteria->verifiedOnly) {
            $filters[] = 'seller_verified = true';
        }

        if ($criteria->deliveryOnly) {
            $filters[] = 'delivery_available = true';
        }

        if ($criteria->inStockOnly) {
            $filters[] = 'in_stock = true';
        }

        if ($criteria->provinceId !== null) {
            $filters[] = 'province_id = '.$criteria->provinceId;
        }

        if ($criteria->cityId !== null) {
            $filters[] = 'city_id = '.$criteria->cityId;
        }

        /*
         * A radius is only meaningful once we know where the buyer is, and a
         * shop that has never been placed on the map has no `_geo` and so
         * drops out of the results entirely — which is the honest answer to
         * "what is within 10km of me".
         */
        if ($criteria->radiusKm !== null && $criteria->hasLocation()) {
            $filters[] = sprintf(
                '_geoRadius(%s, %s, %d)',
                $criteria->latitude,
                $criteria->longitude,
                min($criteria->radiusKm, SearchCriteria::MAX_RADIUS_KM) * self::METRES_PER_KM,
            );
        }

        return $filters;
    }

    private function quote(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }
}
