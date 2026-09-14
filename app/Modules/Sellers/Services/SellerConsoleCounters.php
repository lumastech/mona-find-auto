<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Services;

use App\Modules\Sellers\Models\Seller;
use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounterTone;
use App\Support\Console\ProvidesConsoleCounters;

/**
 * What the staff dashboard says about sellers.
 */
class SellerConsoleCounters implements ProvidesConsoleCounters
{
    public function counters(): array
    {
        $waiting = Seller::query()->inVerificationQueue()->count();

        return [
            new ConsoleCounter(
                key: 'sellers.verification',
                label: 'Sellers awaiting verification',
                value: $waiting,
                href: route('admin.sellers.index', ['queue' => 1]),
                ability: 'moderate',
                /*
                 * An unverified seller cannot trade, so this queue is a shop
                 * in Lusaka waiting on us rather than a tidy-up.
                 */
                tone: $waiting > 0 ? ConsoleCounterTone::Warning : ConsoleCounterTone::Neutral,
                hint: 'Applications submitted, under review or awaiting inspection.',
            ),
        ];
    }
}
