<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A collection was confirmed, by whichever route confirmed it first.
 *
 * Fired exactly once per reference, because the observation that raises it is
 * written under a unique index — a webhook and a verify call racing each other
 * produce one insert and therefore one event.
 *
 * Payments does not settle the orders itself in a listener; it calls Orders
 * directly, and this event is for everybody else — notifications, analytics.
 */
class PaymentSucceeded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}
