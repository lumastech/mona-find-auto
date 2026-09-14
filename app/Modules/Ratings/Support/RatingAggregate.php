<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Support;

/**
 * A star breakdown: how many people said what.
 *
 * The storefront draws five bars from this and the search index reads two
 * numbers off it, so it is computed once per subject and passed around rather
 * than being re-derived by whoever needs it next.
 */
final readonly class RatingAggregate
{
    /**
     * @param  array<int, int>  $breakdown  Counts keyed by star, 1 through 5.
     */
    public function __construct(
        public int $count = 0,
        public ?float $average = null,
        public array $breakdown = [],
        public int $verifiedCount = 0,
    ) {}

    public static function empty(): self
    {
        return new self(breakdown: self::emptyBreakdown());
    }

    /**
     * Build from rows of {stars, total}, as a `groupBy('stars')` returns.
     *
     * @param  array<int, int>  $countsByStar
     */
    public static function fromCounts(array $countsByStar, int $verifiedCount = 0): self
    {
        $breakdown = self::emptyBreakdown();

        foreach ($countsByStar as $stars => $count) {
            $star = (int) $stars;

            if ($star >= 1 && $star <= 5) {
                $breakdown[$star] = (int) $count;
            }
        }

        $count = array_sum($breakdown);

        if ($count === 0) {
            return new self(breakdown: $breakdown);
        }

        $total = 0;

        foreach ($breakdown as $star => $starCount) {
            $total += $star * $starCount;
        }

        return new self(
            count: $count,
            average: round($total / $count, 2),
            breakdown: $breakdown,
            verifiedCount: $verifiedCount,
        );
    }

    /**
     * The share of reviews at each star, as whole percentages for the bars.
     *
     * @return array<int, float>
     */
    public function percentages(): array
    {
        if ($this->count === 0) {
            return self::emptyBreakdown();
        }

        return array_map(
            fn (int $starCount): float => round($starCount / $this->count * 100, 1),
            $this->breakdown,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'average' => $this->average,
            'breakdown' => $this->breakdown,
            'percentages' => $this->percentages(),
            'verified_count' => $this->verifiedCount,
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function emptyBreakdown(): array
    {
        return [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    }
}
