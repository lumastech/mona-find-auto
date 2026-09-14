<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Enums\MatchTier;
use App\Modules\Search\Support\ProductIndex;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Search\Support\SearchResults;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Meilisearch\Endpoints\Indexes;

/**
 * Running a buyer's search against Meilisearch.
 *
 * The ordering the brief asks for — exact matches, then partial ones, then a
 * category fallback, and inside each of those the quality score then price
 * descending — is *not* implemented here. It is implemented once, in the
 * index's ranking rules (see App\Modules\Search\Support\ProductIndex), which
 * is why this class issues one query for the first two tiers rather than two.
 * A second query would need its own de-duplication, its own pagination and
 * its own ordering, and the three would drift.
 *
 * What is here: turning criteria into Meilisearch parameters, labelling each
 * hit with the tier it earned so the storefront can explain itself, and the
 * tier-3 fallback, which is a genuinely different query and is only run when
 * the first one comes back with nothing.
 */
final class ProductSearch
{
    /** Metres to a kilometre. Meilisearch's geo radius is in metres. */
    private const METRES_PER_KM = 1000;

    public function __construct(
        private readonly ProductDocument $documents,
        private readonly FallbackCategoryFinder $fallback,
    ) {}

    public function search(SearchCriteria $criteria): SearchResults
    {
        $results = $this->run($criteria);

        /*
         * Tier 3 only exists to rescue an empty page, and only when the buyer
         * typed something — a filter combination that matches nothing is the
         * buyer's own doing, and quietly ignoring their filters would be a
         * worse answer than none.
         */
        if (! $results->isEmpty() || ! $criteria->hasQuery()) {
            return $results;
        }

        $category = $this->fallback->for($criteria);

        if ($category === null) {
            return $results;
        }

        $related = $this->run($criteria->fallingBackTo((int) $category->getKey()), MatchTier::Related, $category);

        return $related->isEmpty() ? $results : $related;
    }

    /**
     * One Meilisearch round trip, hydrated and labelled.
     */
    private function run(SearchCriteria $criteria, ?MatchTier $forcedTier = null, ?Category $fallbackCategory = null): SearchResults
    {
        $facets = [];

        $paginator = $this->paginator($criteria, $facets);

        [$tiers, $tier] = $this->tiers($paginator, $criteria, $forcedTier);

        return new SearchResults(
            listings: $paginator,
            tier: $tier,
            tiers: $tiers,
            facets: $facets,
            fallbackCategory: $fallbackCategory,
        );
    }

    /**
     * @param  array<string, array<string, int>>  $facets  Filled in by reference.
     * @return LengthAwarePaginator<int, Product>
     */
    private function paginator(SearchCriteria $criteria, array &$facets): LengthAwarePaginator
    {
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
         * paginator's job. Nothing about the contents changes; the query
         * string is re-applied so page two of a filtered search is still the
         * same filtered search.
         */
        return (new LengthAwarePaginator(
            $page->items(),
            $page->total(),
            $criteria->perPage,
            $criteria->page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        ))->appends($criteria->toQueryString());
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

    /**
     * Label every hit on this page, and the page as a whole.
     *
     * The page's tier is the best any of its results managed: a screen whose
     * first result matches every word is not a screen that needs apologising
     * for, even if the tail of it is partial.
     *
     * @param  LengthAwarePaginator<int, Product>  $paginator
     * @return array{array<int, MatchTier>, MatchTier}
     */
    private function tiers(LengthAwarePaginator $paginator, SearchCriteria $criteria, ?MatchTier $forcedTier): array
    {
        if ($forcedTier !== null) {
            $tiers = [];

            foreach ($paginator->items() as $product) {
                $tiers[$product->getKey()] = $forcedTier;
            }

            return [$tiers, $forcedTier];
        }

        $tokens = $criteria->tokens();
        $tiers = [];
        $best = MatchTier::Partial;

        foreach ($paginator->items() as $product) {
            $tier = $tokens->tierFor($this->documents->matchText($product));

            $tiers[$product->getKey()] = $tier;

            if ($tier === MatchTier::Exact) {
                $best = MatchTier::Exact;
            }
        }

        return [$tiers, $tiers === [] ? MatchTier::Exact : $best];
    }

    private function quote(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }
}
