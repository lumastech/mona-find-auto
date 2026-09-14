<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounterTone;
use App\Support\Console\ProvidesConsoleCounters;

/**
 * What the staff dashboard says about listings.
 */
class CatalogConsoleCounters implements ProvidesConsoleCounters
{
    public function counters(): array
    {
        $waiting = Product::query()->awaitingModeration()->count();

        return [
            new ConsoleCounter(
                key: 'listings.moderation',
                label: 'Listings awaiting moderation',
                value: $waiting,
                href: route('admin.listings.index', ['status' => ListingStatus::PendingReview->value]),
                ability: 'moderate',
                tone: $waiting > 0 ? ConsoleCounterTone::Warning : ConsoleCounterTone::Neutral,
                hint: 'Submitted parts that no buyer can see yet.',
            ),
        ];
    }
}
