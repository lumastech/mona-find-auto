<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * How urgently a non-zero counter should read.
 *
 * Tone is the owning module's judgement about its own queue, not a threshold
 * the dashboard applies: one unresolved reconciliation exception is a
 * genuine alarm, while forty listings awaiting moderation is a normal
 * Tuesday. Only the module knows which of its numbers is which.
 */
enum ConsoleCounterTone: string
{
    /** Work in hand. Nothing is wrong. */
    case Neutral = 'neutral';

    /** Somebody is waiting on us, or a window is running out. */
    case Warning = 'warning';

    /** Money or trust is at stake until this is cleared. */
    case Critical = 'critical';
}
