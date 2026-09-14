<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\PayoutBatchStatus;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Models\ReconciliationException;
use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounterTone;
use App\Support\Console\ProvidesConsoleCounters;

/**
 * What the staff dashboard says about money leaving the platform.
 *
 * Both of these carry the `finance` ability, so a moderator's dashboard
 * simply does not have them on it — the tile is not there to be clicked and
 * refused.
 */
class PaymentsConsoleCounters implements ProvidesConsoleCounters
{
    public function counters(): array
    {
        $batches = PayoutBatch::query()
            ->where('status', PayoutBatchStatus::AwaitingApproval)
            ->count();

        $exceptions = ReconciliationException::query()->outstanding()->count();

        return [
            new ConsoleCounter(
                key: 'payouts.awaiting_approval',
                label: 'Payout batches awaiting approval',
                value: $batches,
                href: route('admin.payouts.index'),
                ability: 'finance',
                tone: $batches > 0 ? ConsoleCounterTone::Warning : ConsoleCounterTone::Neutral,
                hint: 'Built by one person; somebody else has to approve them.',
            ),
            new ConsoleCounter(
                key: 'reconciliation.exceptions',
                label: 'Unresolved reconciliation exceptions',
                value: $exceptions,
                href: route('admin.reconciliation.index'),
                ability: 'finance',
                /*
                 * Every one of these is the ledger and Lenco disagreeing
                 * about real money. One is an alarm, not a backlog.
                 */
                tone: $exceptions > 0 ? ConsoleCounterTone::Critical : ConsoleCounterTone::Neutral,
                hint: 'The ledger and Lenco do not agree about an amount.',
            ),
        ];
    }
}
