<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Listeners;

use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Models\OrderDispute;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A moderator decided — move the money the way they said.
 *
 * The amount comes off the event rather than being recomputed here, and that
 * is the point of it being on the event: the figure a moderator agreed with a
 * buyer on the phone and the figure a later recalculation arrives at have no
 * business being two different numbers.
 *
 * Only the refund is posted here. A resolution that completes the order — a
 * release, or a partial refund — moves the order into Completed through the
 * state machine, which fires OrderCompleted, which releases whatever is left
 * in escrow. Posting the release here as well would be the same money moved
 * twice by two different paths.
 *
 * The idempotency key names the DISPUTE, so a resolution replayed by a
 * retried job refunds once, while a second dispute on the same order that
 * happens to end in the same amount refunds again — which is right, because
 * those are two different events.
 */
class PostDisputeResolution implements ShouldQueue
{
    public function __construct(private readonly OrderPostingService $postings) {}

    public function handle(DisputeResolved $event): void
    {
        if ($event->resolution === DisputeResolution::Release || ! $event->refundAmount->isPositive()) {
            return;
        }

        $dispute = $event->dispute->loadMissing('order.seller');
        $order = $dispute->order;

        $this->postings->refund(
            order: $order,
            amount: $event->refundAmount,
            eventKey: $this->keyFor($dispute),
            actor: $dispute->resolver,
            reason: $event->resolution->label().' on dispute #'.$dispute->getKey().'.',
        );
    }

    private function keyFor(OrderDispute $dispute): string
    {
        return 'dispute-resolution:dispute:'.$dispute->getKey();
    }
}
