<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * Implemented by the class a module offers the staff dashboard's stats strip.
 *
 * The counterpart to ProvidesConsoleCounters, with one difference that
 * matters: these are read over a WINDOW, and the same window is handed to
 * every module so the figures on the screen are comparable with each other.
 *
 * @see ConsoleStatistics
 */
interface ProvidesConsoleStatistics
{
    /**
     * The headline figures this module has for the window.
     *
     * Unlike counters, these are allowed to be a little more expensive — the
     * dashboard defers them behind the queue tiles — but they are still
     * aggregate queries over a bounded window, never a per-row pass.
     *
     * @return array<int, ConsoleStat>
     */
    public function stats(ConsoleWindow $window): array;

    /**
     * The charts this module has for the window, if any.
     *
     * Most modules have none. Returning an empty array is the normal answer.
     *
     * @return array<int, ConsoleChart>
     */
    public function charts(ConsoleWindow $window): array;
}
