<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * What kind of quantity a statistic is, so the browser can render it.
 *
 * Money crosses the wire as an integer number of ngwee and is formatted by
 * the same helper the <Money> component uses; a percentage crosses as
 * hundredths of a percent. Neither is ever divided on the server into a
 * float — see App\Support\Money\Money for why that rule has no exceptions.
 */
enum ConsoleStatFormat: string
{
    /** A plain tally of things. */
    case Count = 'count';

    /** Integer ngwee. */
    case Money = 'money';

    /** Hundredths of a percent: 1250 is 12.50%. */
    case Percent = 'percent';
}
