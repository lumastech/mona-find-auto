<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Services;

use App\Modules\Sellers\Models\Seller;
use App\Support\Console\ConsoleStat;
use App\Support\Console\ConsoleStatDirection;
use App\Support\Console\ConsoleWindow;
use App\Support\Console\DailySeries;
use App\Support\Console\ProvidesConsoleStatistics;

/**
 * What the staff console's home screen says about the seller base.
 *
 * Verifications, not applications: an application is a queue and already has
 * a counter tile of its own, while a verification is a shop that can now
 * trade. Counting the same sellers in both places would have staff reading
 * the backlog twice and the growth never.
 */
class SellerConsoleStatistics implements ProvidesConsoleStatistics
{
    public function stats(ConsoleWindow $window): array
    {
        return [
            new ConsoleStat(
                key: 'sellers.verified',
                label: 'Active verified Sellers',
                value: $this->verifiedIn($window),
                previous: $this->verifiedIn($window->previous()),
                direction: ConsoleStatDirection::HigherIsBetter,
                href: route('admin.sellers.index'),
                ability: 'moderate',
                hint: 'Shops that started being able to trade during the window.',
                spark: DailySeries::count(Seller::query(), 'verified_at', $window),
            ),
            new ConsoleStat(
                key: 'sellers.trading',
                label: 'Verified sellers',
                value: Seller::query()->verified()->count(),
                previous: null,
                direction: ConsoleStatDirection::Neutral,
                href: route('admin.sellers.index'),
                ability: 'moderate',
                hint: 'The whole trading base, however long ago each was approved.',
            ),
        ];
    }

    public function charts(ConsoleWindow $window): array
    {
        return [];
    }

    private function verifiedIn(ConsoleWindow $window): int
    {
        return Seller::query()->whereBetween('verified_at', $window->bounds())->count();
    }
}
