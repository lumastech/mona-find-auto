<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Events\DisputeOpened;
use App\Modules\Orders\Notifications\DisputeOpenedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The seller finds out their money is on hold.
 *
 * Queued, but the hold it describes is not: the order was already Disputed
 * and its auto-complete deadline already cleared inside the transaction that
 * created the dispute. This listener only carries the news, so a backed-up
 * queue delays a message rather than releasing an escrow it should not.
 */
class NotifySellerOfDispute implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(DisputeOpened $event): void
    {
        $event->dispute->order->seller->user->notify(
            new DisputeOpenedNotification($event->dispute),
        );
    }
}
