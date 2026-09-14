<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Listeners;

use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Events\OrderCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The order is finished — the money is the seller's.
 *
 * Fires whether the buyer confirmed or the window closed on them; both are
 * completion, and a seller waiting to be paid should not care which. Releases
 * whatever is still held, so an order that was part-refunded during a dispute
 * releases the remainder and MonaFind's commission is worked out on what the
 * buyer actually kept.
 *
 * Does nothing at all on a direct-settlement order, which was split at
 * payment and has no escrow leg to release — no branch needed here, because
 * there is simply no balance to find.
 */
class ReleaseEscrowForCompletedOrder implements ShouldQueue
{
    public function __construct(private readonly OrderPostingService $postings) {}

    public function handle(OrderCompleted $event): void
    {
        $order = $event->order->loadMissing('seller');

        $this->postings->releaseEscrow($order);
    }
}
