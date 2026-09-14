<?php

declare(strict_types=1);

namespace App\Support\Console;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The stretch of time the staff console's statistics cover.
 *
 * Deliberately a separate type from Finance's MetricWindow rather than a
 * reuse of it. Finance is downstream of every module and nothing may depend
 * on it, so a window living there could not be handed to Catalog or Sellers;
 * this one can, and Finance's own provider simply builds a MetricWindow from
 * its two dates.
 *
 * Every boundary is a LUSAKA day boundary. Timestamps are stored in UTC and
 * read by people in Lusaka, so a window built from a UTC "today" starts two
 * hours late and quietly drops the first two hours of trading from whatever
 * it counts.
 *
 * A window also knows the one immediately before it, because a statistic on
 * this screen is only ever shown next to what it was last time — "K 42,000"
 * says nothing on its own, and "K 42,000, up a fifth" says everything.
 */
final readonly class ConsoleWindow
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonInterface $from, CarbonInterface $to)
    {
        $timezone = self::timezone();

        $this->from = CarbonImmutable::instance($from)->setTimezone($timezone)->startOfDay();
        $this->to = CarbonImmutable::instance($to)->setTimezone($timezone)->endOfDay();
    }

    /**
     * The last `$days` days ending today, counted in Lusaka.
     */
    public static function lastDays(int $days): self
    {
        $today = CarbonImmutable::now(self::timezone());

        return new self($today->subDays(max(1, $days) - 1), $today);
    }

    /**
     * The window of the same length immediately before this one.
     *
     * Same length rather than "the previous calendar month", because the
     * comparison has to be fair: a 30-day window compared against a 31-day
     * one shows growth on a flat month.
     */
    public function previous(): self
    {
        $length = $this->days();

        return new self($this->from->subDays($length), $this->from->subDay());
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * The start of every day in the window, in order.
     *
     * Providers zero-fill against this, so a quiet Sunday appears as a zero
     * rather than as a gap the chart closes up and misreads as continuous
     * trading.
     *
     * @return array<int, CarbonImmutable>
     */
    public function dayStarts(): array
    {
        $days = [];
        $cursor = $this->from;

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $days[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * The day labels a chart puts along its x-axis.
     *
     * @return array<int, string>
     */
    public function dayLabels(): array
    {
        return array_map(
            static fn (CarbonImmutable $day): string => $day->format('j M'),
            $this->dayStarts(),
        );
    }

    /**
     * The bounds as the database wants them: UTC, which is what every
     * timestamp column holds.
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
     * @return array{from: string, to: string, label: string, days: int}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $this->label(),
            'days' => $this->days(),
        ];
    }

    private static function timezone(): string
    {
        return (string) config('monafind.display_timezone', 'Africa/Lusaka');
    }
}
