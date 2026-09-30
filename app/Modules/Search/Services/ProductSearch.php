<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Contracts\ListingSearch;
use App\Modules\Search\Enums\MatchTier;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Search\Support\SearchResults;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Running a buyer's search.
 *
 * The ordering the brief asks for — exact matches, then partial ones, then a
 * category fallback, and inside each of those the quality score then price
 * descending — is *not* implemented here. It is implemented once, in the
 * index's ranking rules (see App\Modules\Search\Support\ProductIndex), which
 * is why this class issues one query for the first two tiers rather than two.
 * A second query would need its own de-duplication, its own pagination and
 * its own ordering, and the three would drift.
 *
 * What is here: labelling each hit with the tier it earned so the storefront
 * can explain itself, and the tier-3 fallback, which is a genuinely different
 * query and is only run when the first one comes back with nothing. Talking
 * to the index is Contracts\ListingSearch's job — Meilisearch normally, or
 * the SQL fallback on hosting that cannot run it.
 */
final class ProductSearch
{
    public function __construct(
        private readonly ProductDocument $documents,
        private readonly FallbackCategoryFinder $fallback,
        private readonly ListingSearch $listings,
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
     * One round trip to the index, hydrated and labelled.
     */
    private function run(SearchCriteria $criteria, ?MatchTier $forcedTier = null, ?Category $fallbackCategory = null): SearchResults
    {
        $page = $this->listings->page($criteria);

        [$tiers, $tier] = $this->tiers($page->listings, $criteria, $forcedTier);

        return new SearchResults(
            listings: $page->listings,
            tier: $tier,
            tiers: $tiers,
            facets: $page->facets,
            fallbackCategory: $fallbackCategory,
        );
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
}
