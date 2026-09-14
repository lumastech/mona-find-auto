<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Services;

use App\Modules\Mechanics\Models\MechanicProfile;
use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounterTone;
use App\Support\Console\ProvidesConsoleCounters;

/**
 * What the staff dashboard says about mechanic profiles.
 */
class MechanicConsoleCounters implements ProvidesConsoleCounters
{
    public function counters(): array
    {
        $waiting = MechanicProfile::query()->inApprovalQueue()->count();

        return [
            new ConsoleCounter(
                key: 'mechanics.approval',
                label: 'Mechanics awaiting approval',
                value: $waiting,
                /* The list has no queue filter: with none applied it IS the queue. */
                href: route('admin.mechanics.index'),
                ability: 'moderate',
                tone: $waiting > 0 ? ConsoleCounterTone::Warning : ConsoleCounterTone::Neutral,
                hint: 'Nothing about them is public until this clears.',
            ),
        ];
    }
}
