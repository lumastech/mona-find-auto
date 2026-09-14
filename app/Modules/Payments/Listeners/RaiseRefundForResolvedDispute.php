<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Ledger\Listeners\PostDisputeResolution;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Payments\Services\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A moderator decided the buyer gets money back. Send it.
 *
 * The other half of this event. Ledger's PostDisputeResolution posts what the
 * decision does to the accounts; this raises the Refund row and moves the
 * actual cash. Neither does the other's job, and RefundService is explicitly
 * told not to post for a dispute — two listeners both posting one decision
 * would refund the books twice.
 *
 * @see PostDisputeResolution
 */
class RaiseRefundForResolvedDispute implements ShouldQueue
{
    public function __construct(private readonly RefundService $refunds) {}

    public function handle(DisputeResolved $event): void
    {
        if ($event->resolution === DisputeResolution::Release || ! $event->refundAmount->isPositive()) {
            return;
        }

        $dispute = $event->dispute->loadMissing('order.group');

        $this->refunds->createForDispute($dispute, $event->refundAmount, $dispute->resolver);
    }
}
