<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Support\Console\ConsoleChart;
use App\Support\Console\ConsoleChartSeries;
use App\Support\Console\ConsoleStat;
use App\Support\Console\ConsoleStatDirection;
use App\Support\Console\ConsoleStatFormat;
use App\Support\Console\ConsoleWindow;
use App\Support\Console\DailySeries;
use App\Support\Console\ProvidesConsoleStatistics;

/**
 * What the staff console's home screen says about trade going through.
 *
 * Deliberately not a money figure among them. Finance owns every amount on
 * this platform and reads them all off the ledger; what Orders can say that
 * Finance cannot is how many orders were PLACED — including the ones that
 * never took a payment, which by definition never reach a journal line and
 * are exactly what a moderator wants to see when checkout is misbehaving.
 *
 * The dispute rate is per thousand rather than a percentage. A marketplace
 * with a 0.4% dispute rate rounds to "0%" at one decimal and to nothing at
 * none, and a trust figure that reads as zero when it is not is worse than
 * no figure at all.
 */
class OrderConsoleStatistics implements ProvidesConsoleStatistics
{
    public function stats(ConsoleWindow $window): array
    {
        $placed = $this->placedIn($window);
        $completed = $this->completedIn($window);

        return [
            new ConsoleStat(
                key: 'orders.placed',
                label: 'Orders placed',
                value: $placed,
                previous: $this->placedIn($window->previous()),
                direction: ConsoleStatDirection::HigherIsBetter,
                href: route('admin.orders.index'),
                ability: 'moderate',
                hint: 'Every checkout that produced an order, paid or not.',
                spark: DailySeries::count(Order::query(), 'created_at', $window),
            ),
            new ConsoleStat(
                key: 'orders.completed',
                label: 'Orders completed',
                value: $completed,
                previous: $this->completedIn($window->previous()),
                direction: ConsoleStatDirection::HigherIsBetter,
                href: route('admin.orders.index', ['status' => OrderStatus::Completed->value]),
                ability: 'moderate',
                hint: 'Confirmed by the buyer, or auto-completed at the end of the window.',
            ),
            new ConsoleStat(
                key: 'orders.dispute_rate',
                label: 'Disputes raised',
                value: $this->disputesIn($window),
                previous: $this->disputesIn($window->previous()),
                direction: ConsoleStatDirection::LowerIsBetter,
                href: route('admin.disputes.index'),
                ability: 'moderate',
                hint: $this->disputeRateHint($window, $placed),
                spark: DailySeries::count(OrderDispute::query(), 'created_at', $window),
            ),
        ];
    }

    public function charts(ConsoleWindow $window): array
    {
        return [
            new ConsoleChart(
                key: 'orders.volume',
                title: 'Orders a day',
                labels: $window->dayLabels(),
                series: [
                    new ConsoleChartSeries(
                        label: 'Placed',
                        values: DailySeries::count(Order::query(), 'created_at', $window),
                        colour: ConsoleChart::COLOUR_PRIMARY,
                    ),
                    new ConsoleChartSeries(
                        label: 'Disputed',
                        values: DailySeries::count(OrderDispute::query(), 'created_at', $window),
                        colour: ConsoleChart::COLOUR_DANGER,
                    ),
                ],
                format: ConsoleStatFormat::Count,
                type: 'bar',
                href: route('admin.orders.index'),
                ability: 'moderate',
                description: 'Checkouts that produced an order, against the ones that went wrong.',
            ),
        ];
    }

    private function placedIn(ConsoleWindow $window): int
    {
        return Order::query()->whereBetween('created_at', $window->bounds())->count();
    }

    /**
     * Completed IN the window, read off when the order reached that state.
     *
     * `completed_at` rather than `created_at`: an order placed in August and
     * confirmed in September is September's good news, and counting it in
     * August would also mean the figure for a closed month kept changing
     * after the month closed.
     */
    private function completedIn(ConsoleWindow $window): int
    {
        return Order::query()->whereBetween('completed_at', $window->bounds())->count();
    }

    private function disputesIn(ConsoleWindow $window): int
    {
        return OrderDispute::query()->whereBetween('created_at', $window->bounds())->count();
    }

    /**
     * The rate, in disputes per thousand orders, or nothing to divide by.
     */
    private function disputeRateHint(ConsoleWindow $window, int $placed): string
    {
        if ($placed === 0) {
            return 'No orders were placed in this window.';
        }

        $perThousand = intdiv($this->disputesIn($window) * 2000 + $placed, 2 * $placed);

        return "{$perThousand} in every thousand orders placed.";
    }
}
