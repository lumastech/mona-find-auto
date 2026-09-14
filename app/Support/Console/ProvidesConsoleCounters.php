<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * Implemented by the class a module offers the staff dashboard.
 *
 * @see ConsoleCounters
 */
interface ProvidesConsoleCounters
{
    /**
     * The queues this module currently has outstanding.
     *
     * Called once per dashboard render, so it must be cheap: aggregate
     * queries, no per-row work, no eager loads.
     *
     * @return array<int, ConsoleCounter>
     */
    public function counters(): array;
}
