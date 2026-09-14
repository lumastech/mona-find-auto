<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * Which way is up, for a statistic that has moved.
 *
 * The dashboard cannot work this out for itself: revenue rising a fifth is
 * good news and disputes rising a fifth is not, and both are "+20%". Only the
 * module that owns the number knows which, so it says so and the browser
 * colours the change accordingly.
 *
 * Neutral is a real answer, not a cop-out. "Orders placed" going up is
 * usually good and occasionally just a seasonal spike; a figure nobody would
 * act on is better drawn grey than green.
 */
enum ConsoleStatDirection: string
{
    case HigherIsBetter = 'higher_is_better';

    case LowerIsBetter = 'lower_is_better';

    case Neutral = 'neutral';
}
