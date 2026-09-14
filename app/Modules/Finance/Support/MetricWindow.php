<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The stretch of time a set of figures covers.
 *
 * Half-open in intent and inclusive in SQL: `from` is the start of its day and
 * `to` the end of its day, both in the platform's display timezone before
 * being handed to the database. That conversion is the whole reason this is a
 * type rather than two loose Carbons — a Lusaka finance officer asking for
 * "September" means midnight to midnight in Lusaka, and a window built in UTC
 * quietly moves two hours of trading into the wrong month.
 */
final readonly class MetricWindow
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonInterface $from, CarbonInterface $to)
    {
        $this->from = CarbonImmutable::instance($from)->startOfDay();
        $this->to = CarbonImmutable::instance($to)->endOfDay();
    }

    /**
     * The window a request asked for, or the last 30 days if it asked for
     * nothing sensible.
     */
    public static function fromStrings(?string $from, ?string $to): self
    {
        $timezone = self::timezone();

        $start = self::parse($from, $timezone) ?? CarbonImmutable::now($timezone)->subDays(29);
        $end = self::parse($to, $timezone) ?? CarbonImmutable::now($timezone);

        /* A window typed backwards is a typo, not an empty result. */
        return $start->greaterThan($end)
            ? new self($end, $start)
            : new self($start, $end);
    }

    public static function monthOf(CarbonInterface $moment): self
    {
        $month = CarbonImmutable::instance($moment)->setTimezone(self::timezone());

        return new self($month->startOfMonth(), $month->endOfMonth());
    }

    /**
     * The bounds as the database wants them: UTC, which is what `posted_at`
     * holds.
     *
     * @return array{0: string, 1: string}
     */
    public function bounds(): array
    {
        return [
            $this->from->utc()->toDateTimeString(),
            $this->to->utc()->toDateTimeString(),
        ];
    }

    public function label(): string
    {
        return $this->from->format('j M Y').' – '.$this->to->format('j M Y');
    }

    /**
     * @return array{from: string, to: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $this->label(),
        ];
    }

    private static function parse(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function timezone(): string
    {
        return (string) config('monafind.display_timezone', 'Africa/Lusaka');
    }
}
