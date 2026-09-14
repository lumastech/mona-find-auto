<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How finely a series is cut: by day, by week or by month.
 *
 * Bucketing is done in PHP from a plain `posted_at` rather than with the
 * database's date functions, because those differ between SQLite and MySQL in
 * exactly the places that matter here — week numbering and which day a week
 * starts on — and a dashboard that buckets differently in tests than in
 * production is a dashboard no test can vouch for.
 */
enum MetricGranularity: string
{
    case Day = 'day';

    case Week = 'week';

    case Month = 'month';

    /**
     * The start of the bucket a moment falls in.
     */
    public function bucket(CarbonInterface $moment): CarbonImmutable
    {
        $at = CarbonImmutable::instance($moment);

        return match ($this) {
            self::Day => $at->startOfDay(),
            /* Monday, because that is how a Zambian trading week is counted. */
            self::Week => $at->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $at->startOfMonth(),
        };
    }

    /**
     * Step to the next bucket, so a stretch with no trading still appears as
     * a zero rather than as a gap the chart silently closes up.
     */
    public function advance(CarbonImmutable $bucket): CarbonImmutable
    {
        return match ($this) {
            self::Day => $bucket->addDay(),
            self::Week => $bucket->addWeek(),
            self::Month => $bucket->addMonth(),
        };
    }

    public function labelFor(CarbonImmutable $bucket): string
    {
        return match ($this) {
            self::Day => $bucket->format('j M'),
            self::Week => 'w/c '.$bucket->format('j M'),
            self::Month => $bucket->format('M Y'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Daily',
            self::Week => 'Weekly',
            self::Month => 'Monthly',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
