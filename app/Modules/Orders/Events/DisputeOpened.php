<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\OrderDispute;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A buyer has stopped an order.
 *
 * The hold itself is not done by a listener — the order is already Disputed
 * and the auto-complete sweep already skips it by the time this fires, both
 * inside the same transaction, because a hold that depends on a queue worker
 * is a hold that fails exactly when the queue is backed up.
 *
 * What listens to this is everything else: the seller's notification, the
 * moderator queue's counters, and the dispute-rate figure that decides
 * whether a direct-payment seller keeps direct payment.
 */
class DisputeOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(public OrderDispute $dispute) {}
}
