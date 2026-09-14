<?php

declare(strict_types=1);

namespace App\Modules\Search\Database\Factories;

use App\Modules\Search\Enums\MatchTier;
use App\Modules\Search\Enums\SearchSort;
use App\Modules\Search\Models\SearchQuery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SearchQuery>
 */
class SearchQueryFactory extends Factory
{
    protected $model = SearchQuery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $count = fake()->numberBetween(1, 40);

        return [
            'term' => Str::lower(fake()->word().' '.fake()->word()),
            'filters' => [],
            'sort' => SearchSort::Recommended,
            'tier' => MatchTier::Exact,
            'result_count' => $count,
            'zero_results' => false,
            'user_id' => null,
            'created_at' => now(),
        ];
    }

    /**
     * A search that found nothing — the rows the admin report is built on.
     */
    public function zeroResult(?string $term = null): static
    {
        return $this->state([
            'term' => $term ?? Str::lower(fake()->word().' '.fake()->word().' '.fake()->word()),
            'result_count' => 0,
            'zero_results' => true,
            'tier' => null,
        ]);
    }
}
