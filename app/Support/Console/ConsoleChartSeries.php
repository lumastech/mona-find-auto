<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * One plotted line or bar on a console chart.
 *
 * Values are integers in the chart's own format — ngwee for a money chart, a
 * tally for a count one — and stay integers all the way to the axis. The
 * browser formats every label through the same helper the rest of the
 * platform uses, so nothing is divided into kwacha before it is plotted.
 *
 * The colour is a literal, not a Tailwind token: Chart.js is handed a canvas
 * and a canvas cannot read a CSS variable. Pick from the console palette in
 * ConsoleChart rather than inventing one per module, or two modules' charts
 * end up using the same hue for different things.
 */
final readonly class ConsoleChartSeries
{
    /**
     * @param  array<int, int>  $values  One per label on the chart.
     * @param  string  $colour  A literal hex colour.
     */
    public function __construct(
        public string $label,
        public array $values,
        public string $colour,
    ) {}

    /**
     * @return array{label: string, values: array<int, int>, colour: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'values' => array_values($this->values),
            'colour' => $this->colour,
        ];
    }
}
