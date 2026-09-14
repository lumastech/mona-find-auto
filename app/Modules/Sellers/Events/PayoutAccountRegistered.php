<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Events;

use App\Modules\Sellers\Models\PayoutAccount;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A seller added a payout destination and the gateway confirmed it exists.
 */
class PayoutAccountRegistered
{
    use Dispatchable;

    public function __construct(public readonly PayoutAccount $account) {}
}
