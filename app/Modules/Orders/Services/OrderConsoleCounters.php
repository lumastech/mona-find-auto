<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounterTone;
use App\Support\Console\ProvidesConsoleCounters;

/**
 * What the staff dashboard says about orders and disputes.
 */
class OrderConsoleCounters implements ProvidesConsoleCounters
{
    public function counters(): array
    {
        $disputes = OrderDispute::query()->open()->count();
        $overdue = Order::query()->awaitingSellerConfirmation()->count();

        return [
            new ConsoleCounter(
                key: 'orders.disputes',
                label: 'Open disputes',
                value: $disputes,
                href: route('admin.disputes.index'),
                ability: 'moderate',
                /*
                 * A buyer's money is held and a seller is unpaid until each
                 * of these is decided. Nothing else on this screen stops two
                 * people at once.
                 */
                tone: $disputes > 0 ? ConsoleCounterTone::Critical : ConsoleCounterTone::Neutral,
                hint: 'Money is held on both sides until each is resolved.',
            ),
            new ConsoleCounter(
                key: 'orders.unconfirmed',
                label: 'Orders past the seller confirmation window',
                value: $overdue,
                href: route('admin.orders.index', ['awaiting_confirmation' => 1]),
                ability: 'moderate',
                tone: $overdue > 0 ? ConsoleCounterTone::Warning : ConsoleCounterTone::Neutral,
                hint: 'Paid, and the seller has run out of time to answer.',
            ),
        ];
    }
}
