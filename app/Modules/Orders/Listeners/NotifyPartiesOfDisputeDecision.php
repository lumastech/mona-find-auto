<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Notifications\DisputeResolvedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Both parties, the same message.
 *
 * Not two tailored messages. A dispute described one way to the buyer and
 * another to the seller is a dispute that gets reopened, and the one thing
 * both sides have to be able to agree on afterwards is what was decided.
 *
 * Queued, and nothing that has to be true depends on it: the ledger movement
 * happened inside the resolving transaction, and this only carries the news.
 */
class NotifyPartiesOfDisputeDecision implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(DisputeResolved $event): void
    {
        $order = $event->dispute->order;

        $order->buyer->notify(new DisputeResolvedNotification($event->dispute));
        $order->seller->user->notify(new DisputeResolvedNotification($event->dispute));
    }
}
