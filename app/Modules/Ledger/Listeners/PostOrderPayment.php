<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Listeners;

use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A buyer's money arrived — put it in the accounts.
 *
 * Queued, like every other side effect on the platform, and safe to be
 * queued because the entry it posts is idempotent: a retried job finds the
 * event already recorded and posts nothing. That is the property that makes
 * the whole "side effects go on the queue" rule survivable for money.
 *
 * The recipe is chosen from the order's SNAPSHOT rather than from its
 * seller's current mode, so a seller moved to direct settlement between the
 * payment and this job running still has that payment held in escrow.
 */
class PostOrderPayment implements ShouldQueue
{
    public function __construct(private readonly OrderPostingService $postings) {}

    public function handle(OrderPaid $event): void
    {
        $order = Order::query()->with('seller')->find($event->orderId);

        if ($order === null) {
            return;
        }

        $this->postings->recordPayment($order, $event->reference);
    }
}
