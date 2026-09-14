<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderStateChanged;
use App\Modules\Orders\Notifications\OrderPaidNotification;
use App\Modules\Orders\Notifications\OrderStatusChangedNotification;
use App\Modules\Orders\Notifications\PaymentReceivedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Telling the two people involved what just happened.
 *
 * Hung off OrderStateChanged rather than off the three specific events,
 * because "who needs to hear about which state" is a product decision that
 * will keep changing and should live in one readable list rather than be
 * spread across event subscriptions.
 *
 * Queued: a seller pressing "Dispatch" should not wait on a mail server, and
 * a payment webhook has to answer Lenco quickly.
 */
class NotifyPartiesOfOrderState implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(OrderStateChanged $event): void
    {
        $order = $event->order;

        if ($event->to === OrderStatus::Paid) {
            /*
             * The seller's confirmation window is already running by the time
             * this sends, which is exactly why it sends: an auto-cancelled
             * order costs them a sale and costs the platform a refund.
             */
            $order->seller->user->notify(new OrderPaidNotification($order));

            /*
             * And the buyer's receipt, which is a different message: the
             * seller's is a deadline, the buyer's is proof and a plain
             * statement of who is holding the money.
             */
            $order->buyer->notify(new PaymentReceivedNotification($order));
        }

        if (in_array($event->to, OrderStatusChangedNotification::notifiableStates(), true)) {
            $order->buyer->notify(new OrderStatusChangedNotification($order, $event->to));
        }
    }
}
