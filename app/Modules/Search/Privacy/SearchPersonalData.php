<?php

declare(strict_types=1);

namespace App\Modules\Search\Privacy;

use App\Models\User;
use App\Modules\Privacy\Contracts\PersonalDataSource;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;
use App\Modules\Search\Models\SearchQuery;

/**
 * What a person searched for.
 *
 * ## Detached rather than deleted
 *
 * Erasure nulls `user_id` and leaves the row. The query text — "toyota hilux
 * brake pads" — is not personal data once it is not attached to anybody, and
 * the rows are what tell MonaFind which parts buyers ask for and never find.
 * Deleting them would quietly distort the zero-results report that decides
 * what the platform tries to stock.
 *
 * A search term could in principle contain something personal if somebody
 * typed their phone number into it. That is what the retention sweep is for:
 * `privacy.search_history_days` detaches every query from its account after
 * ninety days regardless of whether anyone asked.
 */
class SearchPersonalData implements PersonalDataSource
{
    public function key(): string
    {
        return 'search';
    }

    /**
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array
    {
        return [
            PersonalDataSection::make(
                'Your searches',
                SearchQuery::query()
                    ->where('user_id', $user->getKey())
                    ->latest('id')
                    ->limit(1000)
                    ->get()
                    ->map(static fn (SearchQuery $query): array => [
                        'What you searched for' => $query->term,
                        'Results' => $query->result_count,
                        'Searched on' => $query->created_at?->toDateTimeString(),
                    ])->all(),
                'Searches made while you were signed in, most recent first. Limited to the last 1,000.',
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array
    {
        return [
            'search_queries' => SearchQuery::query()
                ->where('user_id', $user->getKey())
                ->update(['user_id' => null]),
        ];
    }
}
