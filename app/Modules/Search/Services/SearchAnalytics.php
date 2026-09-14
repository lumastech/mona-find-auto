<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Models\User;
use App\Modules\Search\Models\SearchQuery;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Search\Support\SearchResults;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Writing down what buyers looked for, and reading it back.
 *
 * The zero-result report is the reason this exists. It is the shortest route
 * from "buyers cannot find things" to a specific list of makes, models and
 * part names the reference data is missing — which is a backlog somebody can
 * actually work through, rather than a feeling that search is bad.
 */
final class SearchAnalytics
{
    /** How many days of searches the admin report looks back over by default. */
    public const DEFAULT_REPORT_DAYS = 30;

    /**
     * Record a search.
     *
     * Only the first page is logged. Paging deeper is the same search, and
     * counting it again would rank "the searches people scroll through" as
     * though they were "the searches people run" — the opposite of what the
     * report is for.
     */
    public function record(SearchCriteria $criteria, SearchResults $results, ?User $actor = null): ?SearchQuery
    {
        if ($criteria->page > 1) {
            return null;
        }

        /* A bare page load with nothing typed and nothing filtered is not a search. */
        if (! $criteria->hasQuery() && ! $criteria->hasFilters()) {
            return null;
        }

        $total = $results->total();

        return SearchQuery::query()->create([
            'term' => $criteria->query === null ? null : mb_strtolower($criteria->query),
            'filters' => $criteria->appliedFilters(),
            'sort' => $criteria->sort,
            'tier' => $total > 0 ? $results->tier : null,
            'result_count' => $total,
            'zero_results' => $total === 0,
            'user_id' => $actor?->getKey(),
            'created_at' => now(),
        ]);
    }

    /**
     * The searches that most often came back empty.
     *
     * Grouped on the term alone rather than term-plus-filters: "alternator"
     * failing under four different filter combinations is one gap in the
     * catalogue, not four.
     *
     * @return Collection<int, array{term: string, searches: int, last_searched_at: string}>
     */
    public function topZeroResultTerms(int $days = self::DEFAULT_REPORT_DAYS, int $limit = 50): Collection
    {
        return SearchQuery::query()
            ->zeroResult()
            ->since($this->from($days))
            ->whereNotNull('term')
            ->selectRaw('term, count(*) as searches, max(created_at) as last_searched_at')
            ->groupBy('term')
            ->orderByDesc('searches')
            ->orderByDesc('last_searched_at')
            ->limit($limit)
            ->get()
            ->map(static fn (SearchQuery $row): array => [
                'term' => (string) $row->term,
                'searches' => (int) $row->getAttribute('searches'),
                'last_searched_at' => Carbon::parse((string) $row->getAttribute('last_searched_at'))->toIso8601String(),
            ]);
    }

    /**
     * Headline numbers for the same window.
     *
     * @return array{searches: int, zero_results: int, zero_result_rate: float, distinct_terms: int}
     */
    public function summary(int $days = self::DEFAULT_REPORT_DAYS): array
    {
        $from = $this->from($days);

        $searches = SearchQuery::query()->since($from)->count();
        $zeroResults = SearchQuery::query()->since($from)->zeroResult()->count();

        return [
            'searches' => $searches,
            'zero_results' => $zeroResults,
            'zero_result_rate' => $searches === 0 ? 0.0 : round($zeroResults / $searches * 100, 1),
            'distinct_terms' => SearchQuery::query()->since($from)->whereNotNull('term')->distinct()->count('term'),
        ];
    }

    /**
     * The busiest searches that did find something — context for the list
     * above, so a term with four failures is read next to one with four
     * thousand successes.
     *
     * @return Collection<int, array{term: string, searches: int}>
     */
    public function topTerms(int $days = self::DEFAULT_REPORT_DAYS, int $limit = 20): Collection
    {
        return SearchQuery::query()
            ->since($this->from($days))
            ->whereNotNull('term')
            ->where('zero_results', false)
            ->selectRaw('term, count(*) as searches')
            ->groupBy('term')
            ->orderByDesc('searches')
            ->limit($limit)
            ->get()
            ->map(static fn (SearchQuery $row): array => [
                'term' => (string) $row->term,
                'searches' => (int) $row->getAttribute('searches'),
            ]);
    }

    private function from(int $days): CarbonInterface
    {
        return now()->subDays(max(1, $days))->startOfDay();
    }
}
