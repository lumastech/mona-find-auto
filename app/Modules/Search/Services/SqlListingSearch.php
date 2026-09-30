<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Contracts\ListingSearch;
use App\Modules\Search\Enums\SearchSort;
use App\Modules\Search\Support\ListingPage;
use App\Modules\Search\Support\ProductIndex;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Search\Support\SqlListingRow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One page of listings from the `search_listings` table, for hosting that
 * cannot run Meilisearch.
 *
 * It reproduces the Meilisearch ranking rules in ORDER BY rather than
 * approximating them with a different idea of relevance, so switching driver
 * changes how well a search copes with typos — this one does not — and
 * nothing else:
 *
 *   words      — Meilisearch's "last" matching strategy: every word first,
 *                then listings that match all but the last word, and so on.
 *                A listing must hold at least the first word to be returned.
 *   attribute  — a listing whose own name, numbers and fitment hold every
 *                word beats one that only matched through its category or
 *                description.
 *   sort       — the buyer's chosen order, below relevance.
 *   quality    — the index-time quality score, descending.
 *   price desc — the brief's tiebreaker, price-on-request last.
 *
 * Distance uses an equirectangular approximation in integer microdegrees:
 * within Zambia and inside the 500km radius cap the error is well under 1%,
 * and it needs nothing but arithmetic, which MySQL and SQLite share.
 */
final class SqlListingSearch implements ListingSearch
{
    /** Kilometres in one degree of latitude. */
    private const KM_PER_DEGREE = 111.195;

    /** The cosine of the buyer's latitude, scaled to an integer by this. */
    private const COSINE_SCALE = 1000;

    /**
     * Words beyond this are ignored. The relevance expression grows with the
     * square of the word count, and nobody looking for a part types twelve.
     */
    private const MAX_TOKENS = 8;

    /** The facets that are yes/no and are reported as a count of "true". */
    private const FLAG_FACETS = ['inspected', 'seller_verified', 'delivery_available'];

    /** FLAG_FACETS, counted in one pass. */
    private const FLAG_TOTALS = 'sum(case when inspected = 1 then 1 else 0 end) as inspected, '
        .'sum(case when seller_verified = 1 then 1 else 0 end) as seller_verified, '
        .'sum(case when delivery_available = 1 then 1 else 0 end) as delivery_available';

    public function page(SearchCriteria $criteria): ListingPage
    {
        $tokens = array_slice($criteria->tokens()->tokens, 0, self::MAX_TOKENS);

        $matching = $this->matching($criteria, $tokens);

        $total = (clone $matching)->count();

        /** @var array<int, int> $ids */
        $ids = $this->ordered(clone $matching, $criteria, $tokens)
            ->forPage($criteria->page, $criteria->perPage)
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return ListingPage::of($this->hydrate($ids), $total, $criteria, $this->facets($matching));
    }

    /**
     * Every row the buyer's words and filters allow, unordered.
     *
     * @param  array<int, string>  $tokens
     */
    private function matching(SearchCriteria $criteria, array $tokens): Builder
    {
        $query = DB::table(SqlListingRow::TABLE);

        if ($tokens !== []) {
            [$sql, $binding] = $this->wordMatches('search_text', $tokens, 0);
            $query->whereRaw($sql, [$binding]);
        }

        if ($criteria->categoryId !== null) {
            $category = Category::query()->find($criteria->categoryId);

            /* A category that does not exist has nothing filed under it. */
            $category === null
                ? $query->whereRaw('1 = 0')
                : $query->where('category_path', 'like', $category->subtreePattern());
        }

        $query->when($criteria->makeId, fn (Builder $query, int $id) => $query->where('make_id', $id));
        $query->when($criteria->vehicleModelId, fn (Builder $query, int $id) => $query->where('vehicle_model_id', $id));

        if ($criteria->year !== null) {
            $query->where('year_from', '<=', $criteria->year)->where('year_to', '>=', $criteria->year);
        }

        $query->when($criteria->condition, fn (Builder $query) => $query->where('condition', $criteria->condition?->value));
        $query->when($criteria->inspectedOnly, fn (Builder $query) => $query->where('inspected', true));
        $query->when($criteria->sourcing, fn (Builder $query) => $query->where('sourcing', $criteria->sourcing?->value));
        $query->when($criteria->minPriceNgwee !== null, fn (Builder $query) => $query->where('price_ngwee', '>=', $criteria->minPriceNgwee));
        $query->when($criteria->maxPriceNgwee !== null, fn (Builder $query) => $query->where('price_ngwee', '<=', $criteria->maxPriceNgwee));
        $query->when($criteria->sellerType, fn (Builder $query) => $query->where('seller_type', $criteria->sellerType?->value));
        $query->when($criteria->verifiedOnly, fn (Builder $query) => $query->where('seller_verified', true));
        $query->when($criteria->deliveryOnly, fn (Builder $query) => $query->where('delivery_available', true));
        $query->when($criteria->inStockOnly, fn (Builder $query) => $query->where('in_stock', true));
        $query->when($criteria->provinceId, fn (Builder $query, int $id) => $query->where('province_id', $id));
        $query->when($criteria->cityId, fn (Builder $query, int $id) => $query->where('city_id', $id));

        /*
         * A shop never placed on the map has no coordinates and drops out,
         * as it does from Meilisearch's _geoRadius.
         */
        if ($criteria->radiusKm !== null && $criteria->latitude !== null && $criteria->longitude !== null) {
            $radius = SqlListingRow::microdegrees(min($criteria->radiusKm, SearchCriteria::MAX_RADIUS_KM) / self::KM_PER_DEGREE);
            $latitude = SqlListingRow::microdegrees($criteria->latitude);
            [$distance, $bindings] = $this->distanceSquared($criteria->latitude, $criteria->longitude);

            $query->whereNotNull('latitude_e6')
                /* A band of latitude first, which the index on latitude_e6 can serve. */
                ->whereBetween('latitude_e6', [$latitude - $radius, $latitude + $radius])
                ->whereRaw($distance.' <= ?', [...$bindings, $radius * $radius]);
        }

        return $query;
    }

    /**
     * The ranking rules, as ORDER BY clauses.
     *
     * @param  array<int, string>  $tokens
     */
    private function ordered(Builder $query, SearchCriteria $criteria, array $tokens): Builder
    {
        if ($tokens !== []) {
            [$words, $wordBindings] = $this->leadingWordsMatched($tokens);
            $query->orderByRaw($words.' desc', $wordBindings);

            [$attribute, $attributeBindings] = $this->allWordsMatch('match_text', $tokens);
            $query->orderByRaw('case when '.$attribute.' then 1 else 0 end desc', $attributeBindings);
        }

        match ($criteria->sort) {
            SearchSort::Recommended => null,
            SearchSort::PriceAsc => $query->orderByRaw('price_ngwee is null')->orderBy('price_ngwee'),
            SearchSort::PriceDesc => $query->orderByRaw('price_ngwee is null')->orderByDesc('price_ngwee'),
            SearchSort::Newest => $query->orderByRaw('published_timestamp is null')->orderByDesc('published_timestamp'),
            SearchSort::Nearest => $this->nearestFirst($query, $criteria),
        };

        return $query
            ->orderByDesc('quality_hundredths')
            ->orderByRaw('price_ngwee is null')
            ->orderByDesc('price_ngwee')
            ->orderByDesc('product_id');
    }

    private function nearestFirst(Builder $query, SearchCriteria $criteria): void
    {
        /* SearchCriteria never hands over Nearest without a location; this is belt and braces. */
        if ($criteria->latitude === null || $criteria->longitude === null) {
            return;
        }

        [$distance, $bindings] = $this->distanceSquared($criteria->latitude, $criteria->longitude);

        $query->orderByRaw('latitude_e6 is null')->orderByRaw($distance, $bindings);
    }

    /**
     * How many of the buyer's words, counted from the first, a listing holds
     * without a gap — Meilisearch's "last" matching strategy as a number.
     *
     * @param  array<int, string>  $tokens
     * @return array{literal-string, array<int, int|string>}
     */
    private function leadingWordsMatched(array $tokens): array
    {
        $cases = [];
        $bindings = [];

        for ($count = count($tokens); $count >= 1; $count--) {
            [$condition, $conditionBindings] = $this->allWordsMatch('search_text', $tokens, $count);
            $cases[] = 'when '.$condition.' then ?';
            array_push($bindings, ...$conditionBindings);
            $bindings[] = $count;
        }

        return ['case '.implode(' ', $cases).' else 0 end', $bindings];
    }

    /**
     * Whether a column holds the first `$count` words (all of them by default).
     *
     * @param  'search_text'|'match_text'  $column
     * @param  array<int, string>  $tokens
     * @return array{literal-string, array<int, string>}
     */
    private function allWordsMatch(string $column, array $tokens, ?int $count = null): array
    {
        $conditions = [];
        $bindings = [];

        foreach (array_slice($tokens, 0, $count ?? count($tokens)) as $index => $token) {
            [$conditions[], $bindings[]] = $this->wordMatches($column, $tokens, $index);
        }

        return ['('.implode(' and ', $conditions).')', $bindings];
    }

    /**
     * One word as a whole word, or the last word typed as a prefix — how
     * SearchTokens reads a half-typed query, and how Meilisearch does.
     *
     * Tokens are letters and digits only, so none of LIKE's wildcards can
     * reach the pattern.
     *
     * @param  'search_text'|'match_text'  $column
     * @param  array<int, string>  $tokens
     * @return array{literal-string, string}
     */
    private function wordMatches(string $column, array $tokens, int $index): array
    {
        $isLast = $index === count($tokens) - 1;

        return [$column.' like ?', '% '.$tokens[$index].($isLast ? '%' : ' %')];
    }

    /**
     * Squared distance from the buyer, in microdegrees of latitude.
     *
     * Integers throughout: PDO binds a PHP float as a string, which SQLite
     * then compares against the wrong type.
     *
     * @return array{literal-string, array<int, int>}
     */
    private function distanceSquared(float $latitude, float $longitude): array
    {
        $lat = SqlListingRow::microdegrees($latitude);
        $lng = SqlListingRow::microdegrees($longitude);
        $cosine = (int) round(cos(deg2rad($latitude)) * self::COSINE_SCALE);
        $scale = self::COSINE_SCALE;

        return [
            '((latitude_e6 - ?) * (latitude_e6 - ?) + ((longitude_e6 - ?) * ? / ?) * ((longitude_e6 - ?) * ? / ?))',
            [$lat, $lat, $lng, $cosine, $scale, $lng, $cosine, $scale],
        ];
    }

    /**
     * The listings, in the order the ids came back.
     *
     * @param  array<int, int>  $ids
     * @return array<int, Product>
     */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $products = Product::query()
            ->with([...ProductDocument::relations(), 'media'])
            ->whereKey($ids)
            ->get()
            ->keyBy(static fn (Product $product): int => (int) $product->getKey());

        $ordered = [];

        foreach ($ids as $id) {
            $product = $products->get($id);

            if ($product !== null) {
                $ordered[] = $product;
            }
        }

        return $ordered;
    }

    /**
     * Counts across the whole matching set, shaped like Meilisearch's facet
     * distribution so Services\FacetPresenter reads either.
     *
     * @return array<string, array<string, int>>
     */
    private function facets(Builder $matching): array
    {
        $facets = [];

        foreach (ProductIndex::facetAttributes() as $attribute) {
            if (in_array($attribute, self::FLAG_FACETS, true)) {
                continue;
            }

            $facets[$attribute] = (clone $matching)
                ->whereNotNull($attribute)
                ->groupBy($attribute)
                ->select($attribute.' as facet_value')
                ->selectRaw('count(*) as facet_count')
                ->pluck('facet_count', 'facet_value')
                ->mapWithKeys(static fn (mixed $count, int|string $value): array => [(string) $value => (int) $count])
                ->all();
        }

        $totals = (array) (clone $matching)->selectRaw(self::FLAG_TOTALS)->first();

        foreach (self::FLAG_FACETS as $attribute) {
            $facets[$attribute] = ['true' => (int) ($totals[$attribute] ?? 0)];
        }

        return $facets;
    }
}
