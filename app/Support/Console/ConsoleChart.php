<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * One chart on the staff console's home screen.
 *
 * A chart earns its place by showing a SHAPE that a number cannot: whether
 * trade is climbing or a quiet fortnight is actually a cliff. Anything whose
 * whole meaning fits in one figure belongs in a ConsoleStat instead — a chart
 * of a number that only ever goes up is decoration, and decoration on a
 * working screen costs a staff member a scroll every morning.
 *
 * Modules contribute these the same way they contribute counters, and the
 * dashboard renders whatever came back without knowing what any of it means.
 */
final readonly class ConsoleChart
{
    /**
     * The console's shared chart palette.
     *
     * Literal hex because a canvas cannot read a CSS variable, and shared so
     * that two modules do not draw different things in the same hue. These
     * are the chart-only counterparts of the brand roles in app.css — gold
     * is absent deliberately: it is the trust mark, and spending it on a
     * trend line stops it meaning "MonaFind vouched for this".
     */
    public const COLOUR_PRIMARY = '#1F2A44';

    public const COLOUR_SUCCESS = '#0F766E';

    public const COLOUR_DANGER = '#B42318';

    public const COLOUR_WARNING = '#B4530E';

    public const COLOUR_INFO = '#2563EB';

    /**
     * @param  string  $key  Stable identifier, e.g. "finance.trade".
     * @param  string  $title  What the chart shows.
     * @param  array<int, string>  $labels  The x-axis, one entry per point.
     * @param  array<int, ConsoleChartSeries>  $series  What is plotted.
     * @param  ConsoleStatFormat  $format  How to render values and ticks.
     * @param  'line'|'bar'  $type
     * @param  string|null  $href  Where the full version of this lives.
     * @param  string|null  $ability  Gate checked before the chart is shown.
     * @param  string|null  $description  One line under the title.
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $labels,
        public array $series,
        public ConsoleStatFormat $format = ConsoleStatFormat::Count,
        public string $type = 'line',
        public ?string $href = null,
        public ?string $ability = null,
        public ?string $description = null,
    ) {}

    /**
     * Whether anything was actually plotted.
     *
     * A chart of thirty zeroes is a flat line at the bottom of an empty box,
     * which reads as a broken widget rather than as a quiet month. The
     * registry drops these and the dashboard says nothing instead.
     */
    public function hasData(): bool
    {
        foreach ($this->series as $series) {
            foreach ($series->values as $value) {
                if ($value !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{key: string, title: string, description: string|null, labels: array<int, string>, series: array<int, array{label: string, values: array<int, int>, colour: string}>, format: string, type: string, href: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'labels' => array_values($this->labels),
            'series' => array_map(
                static fn (ConsoleChartSeries $series): array => $series->toArray(),
                array_values($this->series),
            ),
            'format' => $this->format->value,
            'type' => $this->type,
            'href' => $this->href,
        ];
    }
}
