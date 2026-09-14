<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * How a division that does not land on a whole ngwee should be resolved.
 */
enum RoundingMode: string
{
    /** Round halves away from zero — the default for customer-facing amounts. */
    case HalfUp = 'half_up';

    /** Round halves towards zero. */
    case HalfDown = 'half_down';

    /** Round halves to the nearest even ngwee (banker's rounding). */
    case HalfEven = 'half_even';

    /** Always round towards negative infinity. */
    case Floor = 'floor';

    /** Always round towards positive infinity. */
    case Ceiling = 'ceiling';

    /** Refuse to round: the division must be exact. */
    case Exact = 'exact';
}
