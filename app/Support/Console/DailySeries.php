<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Modules\Finance\Services\FinanceMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A daily tally for a console sparkline or chart, zero-filled and in Lusaka.
 *
 * Three modules want the same thing — how many of these happened on each day
 * of the window — and all three would otherwise write the same timezone shift
 * slightly differently. The shift is not optional: `created_at` is UTC and the
 * person reading is in Lusaka, so a plain `DATE(created_at)` puts everything
 * between midnight and 02:00 on the previous day.
 *
 * ## Why the expression is built the way it is
 *
 * Zambia has never observed daylight saving, so a fixed offset is exact
 * rather than an approximation. The offset is BOUND, which is what forces the
 * `local_date` ALIAS in the GROUP BY: Laravel runs real prepared statements,
 * so MySQL sees a placeholder rather than a number and under ONLY_FULL_GROUP_BY
 * cannot prove that two expressions each holding their own `?` are the same
 * one. It rejects the query as having the timestamp outside the GROUP BY —
 * and SQLite, which the suite runs on, accepts it happily. That combination
 * passes tests and 500s in the browser, so it is worth the alias.
 *
 * The COLUMN cannot be bound — no database takes an identifier as a parameter
 * — so it is typed `literal-string`, which stops PHPStan letting anything
 * that came from a request reach it at all, and validated against a strict
 * pattern at runtime for the paths static analysis does not cover.
 *
 * @see FinanceMetrics::localDateExpression() — the
 *      ledger's own copy of this, which cannot share it: it buckets folded
 *      movement rows rather than counting table rows.
 */
final class DailySeries
{
    /** A bare column, optionally table-qualified. Nothing else is allowed. */
    private const COLUMN_PATTERN = '/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/';

    /**
     * One count per day of the window, in order, zero-filled.
     *
     * @param  Builder<covariant Model>  $query  Already scoped to whatever is
     *                                           being counted; this adds only
     *                                           the window and the grouping.
     * @param  literal-string  $column  A timestamp column, written out at the
     *                                  call site — never assembled from input.
     * @return array<int, int>
     */
    public static function count(Builder $query, string $column, ConsoleWindow $window): array
    {
        [$expression, $bindings] = self::localDateExpression($column);
        [$from, $to] = $window->bounds();

        /** @var array<string, int> $tallies */
        $tallies = $query->clone()
            ->whereBetween($column, [$from, $to])
            ->selectRaw($expression.' as local_date', $bindings)
            ->selectRaw('COUNT(*) as tally')
            ->groupBy('local_date')
            ->pluck('tally', 'local_date')
            ->map(static fn (mixed $tally): int => (int) $tally)
            ->all();

        return array_map(
            static fn (CarbonImmutable $day): int => $tallies[$day->toDateString()] ?? 0,
            $window->dayStarts(),
        );
    }

    /**
     * A SQL expression giving the LOCAL date of a UTC timestamp column.
     *
     * @param  literal-string  $column
     * @return array{0: literal-string, 1: array<int, string|int>}
     */
    private static function localDateExpression(string $column): array
    {
        if (preg_match(self::COLUMN_PATTERN, $column) !== 1) {
            throw new InvalidArgumentException("[{$column}] is not a plain column name.");
        }

        $minutes = (int) (CarbonImmutable::now(
            (string) config('monafind.display_timezone', 'Africa/Lusaka'),
        )->getOffset() / 60);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => ["date({$column}, ?)", [sprintf('%+d minutes', $minutes)]],
            'pgsql' => ["date({$column} + (? || ' minutes')::interval)", [$minutes]],
            default => ["DATE(DATE_ADD({$column}, INTERVAL ? MINUTE))", [$minutes]],
        };
    }
}
