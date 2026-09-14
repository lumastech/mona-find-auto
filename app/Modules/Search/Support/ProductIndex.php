<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

/**
 * The Meilisearch configuration for the listings index, in one place.
 *
 * config/scout.php points at settings() below so that `scout:sync-index-
 * settings` and the module's own tests read the same array. The reasoning
 * behind each block is in app/Modules/Search/README.md; what follows is the
 * short version of the part that matters most.
 *
 * RANKING RULES ARE THE TIERS. The brief asks for exact matches above partial
 * ones, and within a tier for quality score then price descending. That is
 * what this list says, read top to bottom:
 *
 *   words      — a listing matching every word the buyer typed outranks one
 *                matching fewer. This is tier 1 above tier 2, and it is why
 *                the module does not run two queries to produce them.
 *   typo       — real matches above ones that needed a correction.
 *   proximity  — "brake pads" beats "brake ... pads".
 *   attribute  — a hit in the name beats a hit in the description.
 *   sort       — where an alternative sort (price, newest, nearest) takes
 *                effect. Below relevance on purpose: sorting by price should
 *                not drag a wiper blade above the brake pads somebody asked
 *                for. With no query typed, every document ties above this
 *                line and the sort is global, which is what a buyer browsing
 *                a category expects.
 *   exactness  — a whole-word match beats a prefix match.
 *   quality    — the scored ordering inside a tier.
 *   price desc — the brief's tiebreaker.
 *
 * PRICE DESCENDING IS DELIBERATE. It is the one rule here that surprises
 * people. Cheapest-first is what a buyer would sort by themselves, and the
 * default is not trying to be that: among parts that match equally well from
 * shops the platform rates equally, the more expensive listing is more often
 * the complete, boxed, warrantied one. Buyers who want the cheapest have a
 * sort control that is always on screen.
 */
final class ProductIndex
{
    /**
     * The unprefixed index name. Scout adds `scout.prefix` to it.
     */
    public const NAME = 'products';

    /**
     * @return array<int, string>
     */
    public static function rankingRules(): array
    {
        return [
            'words',
            'typo',
            'proximity',
            'attribute',
            'sort',
            'exactness',
            'quality_score:desc',
            'price_ngwee:desc',
        ];
    }

    /**
     * Searched in this order, because `attribute` ranks by it: a part number
     * in the name beats the same string buried in a description.
     *
     * @return array<int, string>
     */
    public static function searchableAttributes(): array
    {
        return [
            'name',
            'part_number',
            'oem_number',
            'make',
            'vehicle_model',
            'category_name',
            'category_path',
            'seller_name',
            'description',
        ];
    }

    /**
     * Everything the facet sidebar filters on, plus `_geo` for the radius.
     *
     * Meilisearch silently returns the wrong thing when you filter on an
     * attribute that is not in this list, so anything the request object
     * accepts has to appear here.
     *
     * @return array<int, string>
     */
    public static function filterableAttributes(): array
    {
        return [
            'category_id',
            'category_ids',
            'make_id',
            'vehicle_model_id',
            'year_from',
            'year_to',
            'years',
            'condition',
            'inspected',
            'sourcing',
            'price_ngwee',
            'seller_id',
            'seller_type',
            'seller_verified',
            'delivery_available',
            'in_stock',
            'province_id',
            'city_id',
            'freshness_state',
            'dispute_band',
            '_geo',
        ];
    }

    /**
     * `quality_score` and `price_ngwee` are here because a custom ranking
     * rule can only name a sortable attribute — not because anything sorts
     * on them directly.
     *
     * @return array<int, string>
     */
    public static function sortableAttributes(): array
    {
        return [
            'quality_score',
            'price_ngwee',
            'published_at',
            '_geo',
        ];
    }

    /**
     * The facets the sidebar draws counts from.
     *
     * @return array<int, string>
     */
    public static function facetAttributes(): array
    {
        return [
            'condition',
            'inspected',
            'sourcing',
            'make_id',
            'vehicle_model_id',
            'category_id',
            'seller_type',
            'seller_verified',
            'delivery_available',
            'province_id',
            'city_id',
        ];
    }

    /**
     * The whole settings payload, as `scout:sync-index-settings` wants it.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return [
            'rankingRules' => self::rankingRules(),
            'searchableAttributes' => self::searchableAttributes(),
            'filterableAttributes' => self::filterableAttributes(),
            'sortableAttributes' => self::sortableAttributes(),
            'faceting' => [
                /* Enough for every make and town in the reference lists. */
                'maxValuesPerFacet' => 200,
            ],
            'pagination' => [
                /*
                 * Meilisearch caps at 1000 documents by default, and a buyer
                 * who has paged 40 screens into "brake pads" needs a filter,
                 * not page 41.
                 */
                'maxTotalHits' => 1000,
            ],
            /*
             * Zambian sellers write part names in English with model numbers
             * mixed in. Meilisearch's default typo tolerance would happily
             * turn "MR906146" into a neighbouring part number, so numbers
             * stay exact and only words are corrected.
             */
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => [
                    'oneTypo' => 5,
                    'twoTypos' => 9,
                ],
                'disableOnAttributes' => ['part_number', 'oem_number'],
            ],
        ];
    }
}
