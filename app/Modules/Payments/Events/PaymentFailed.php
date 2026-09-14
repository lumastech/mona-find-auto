<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A collection attempt failed for good.
 *
 * Not the same as "the buyer closed the widget": that is an abandoned attempt
 * which may yet succeed on the handset. This is the gateway saying no.
 */
class PaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}
