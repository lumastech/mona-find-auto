<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounterTone;
use App\Support\Console\ProvidesConsoleCounters;

/**
 * What the staff dashboard says about stock freshness.
 */
class InventoryConsoleCounters implements ProvidesConsoleCounters
{
    public function __construct(private readonly StaleStockDirectory $directory) {}

    public function counters(): array
    {
        $sellers = $this->directory->sellerCount();

        return [
            new ConsoleCounter(
                key: 'inventory.stale_stock',
                label: 'Sellers with stale stock',
                value: $sellers,
                href: route('admin.stale-stock'),
                ability: 'moderate',
                tone: $sellers > 0 ? ConsoleCounterTone::Warning : ConsoleCounterTone::Neutral,
                hint: 'Listings past the confirmation window, counted per shop.',
            ),
        ];
    }
}
