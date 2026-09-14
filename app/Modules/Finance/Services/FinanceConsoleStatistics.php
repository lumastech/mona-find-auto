<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\MetricGranularity;
use App\Modules\Finance\Support\MetricWindow;
use App\Support\Console\ConsoleChart;
use App\Support\Console\ConsoleChartSeries;
use App\Support\Console\ConsoleStat;
use App\Support\Console\ConsoleStatDirection;
use App\Support\Console\ConsoleStatFormat;
use App\Support\Console\ConsoleWindow;
use App\Support\Console\ProvidesConsoleStatistics;

/**
 * What the staff console's home screen says about money.
 *
 * Every figure here is a sum of journal lines, because FinanceMetrics is the
 * only thing on the platform allowed to answer these questions and the only
 * thing whose answers are guaranteed to balance. A GMV taken off the orders
 * table would count an order whose payment silently failed, and the console
 * and the finance dashboard would then disagree about the same month.
 *
 * Everything carries the `finance` ability, so a moderator's console has no
 * money on it at all rather than a row of tiles that refuse to open.
 *
 * The flows are compared against the window before; the escrow position is
 * not. "Escrow held, up 12% on last month" is a sentence about a balance
 * that was never a flow — the number includes money taken in August — so the
 * tile shows the balance and says nothing about a trend.
 */
class FinanceConsoleStatistics implements ProvidesConsoleStatistics
{
    /**
     * The window cut into days, read once however many tiles want it.
     *
     * Memoised on the instance rather than cached: the provider is resolved
     * fresh per dashboard render, and four tiles plus a chart would otherwise
     * be five identical walks of the same journal lines.
     *
     * @var array<string, array<int, array<string, int|string>>>
     */
    private array $seriesCache = [];

    public function __construct(private readonly FinanceMetrics $metrics) {}

    public function stats(ConsoleWindow $window): array
    {
        $current = $this->metrics->totals($this->metricWindow($window));
        $previous = $this->metrics->totals($this->metricWindow($window->previous()));
        $positions = $this->metrics->positions($this->metricWindow($window));
        $href = route('admin.finance.dashboard');

        return [
            new ConsoleStat(
                key: 'finance.gmv',
                label: 'Gross merchandise value',
                value: $current->gmv->ngwee,
                previous: $previous->gmv->ngwee,
                format: ConsoleStatFormat::Money,
                direction: ConsoleStatDirection::HigherIsBetter,
                href: $href,
                ability: 'finance',
                hint: 'What buyers actually paid, off the ledger.',
                spark: $this->daily($window, 'gmvNgwee'),
            ),
            new ConsoleStat(
                key: 'finance.net_revenue',
                label: 'Net revenue',
                value: $current->netRevenue()->ngwee,
                previous: $previous->netRevenue()->ngwee,
                format: ConsoleStatFormat::Money,
                direction: ConsoleStatDirection::HigherIsBetter,
                href: $href,
                ability: 'finance',
                hint: 'Commission and fees, less refunds absorbed and Lenco charges.',
                spark: $this->daily($window, 'netRevenueNgwee'),
            ),
            new ConsoleStat(
                key: 'finance.orders_paid',
                label: 'Orders paid',
                value: $current->orderCount,
                previous: $previous->orderCount,
                direction: ConsoleStatDirection::HigherIsBetter,
                href: $href,
                ability: 'finance',
                hint: 'Orders on which money reached the platform.',
                spark: $this->daily($window, 'orderCount'),
            ),
            new ConsoleStat(
                key: 'finance.refunds',
                label: 'Refunded to buyers',
                value: $current->refundsToBuyers->ngwee,
                previous: $previous->refundsToBuyers->ngwee,
                format: ConsoleStatFormat::Money,
                direction: ConsoleStatDirection::LowerIsBetter,
                href: $href,
                ability: 'finance',
                hint: 'Cash that went back out, however it was funded.',
                spark: $this->daily($window, 'refundsToBuyersNgwee'),
            ),
            new ConsoleStat(
                key: 'finance.escrow_held',
                label: 'Held in escrow',
                value: $positions->escrowHeld->ngwee,
                /* A balance, not a flow — see the class docblock. */
                previous: null,
                format: ConsoleStatFormat::Money,
                direction: ConsoleStatDirection::Neutral,
                href: $href,
                ability: 'finance',
                hint: "Buyers' money the platform is holding right now.",
            ),
        ];
    }

    public function charts(ConsoleWindow $window): array
    {
        $series = $this->series($window);

        return [
            new ConsoleChart(
                key: 'finance.trade',
                title: 'Trade',
                labels: array_map(
                    static fn (array $point): string => (string) $point['label'],
                    $series,
                ),
                series: [
                    new ConsoleChartSeries(
                        label: 'Taken',
                        values: $this->column($series, 'gmvNgwee'),
                        colour: ConsoleChart::COLOUR_INFO,
                    ),
                    new ConsoleChartSeries(
                        label: 'Net revenue',
                        values: $this->column($series, 'netRevenueNgwee'),
                        colour: ConsoleChart::COLOUR_SUCCESS,
                    ),
                    new ConsoleChartSeries(
                        label: 'Refunded',
                        values: $this->column($series, 'refundsToBuyersNgwee'),
                        colour: ConsoleChart::COLOUR_DANGER,
                    ),
                ],
                format: ConsoleStatFormat::Money,
                href: route('admin.finance.dashboard'),
                ability: 'finance',
                description: 'What came in, what the platform kept, and what went back out.',
            ),
        ];
    }

    /**
     * One value per day, for a sparkline.
     *
     * @return array<int, int>
     */
    private function daily(ConsoleWindow $window, string $field): array
    {
        return $this->column($this->series($window), $field);
    }

    /**
     * @param  array<int, array<string, int|string>>  $series
     * @return array<int, int>
     */
    private function column(array $series, string $field): array
    {
        return array_map(
            static fn (array $point): int => (int) ($point[$field] ?? 0),
            $series,
        );
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function series(ConsoleWindow $window): array
    {
        return $this->seriesCache[$window->from->toDateString().$window->to->toDateString()]
            ??= $this->metrics->series($this->metricWindow($window), MetricGranularity::Day);
    }

    private function metricWindow(ConsoleWindow $window): MetricWindow
    {
        return new MetricWindow($window->from, $window->to);
    }
}
