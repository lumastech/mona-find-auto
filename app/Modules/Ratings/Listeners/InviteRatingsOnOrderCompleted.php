<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Listeners;

use App\Modules\Orders\Events\OrderCompleted;
use App\Modules\Ratings\Notifications\RatingInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A completed order is what entitles each side to rate the other, so it is
 * also the moment to ask.
 *
 * Both sides are invited, and both invitations say what happens to what they
 * write — a seller rating a buyer should know it is private, and a buyer
 * should know theirs is not.
 *
 * This listener only carries news. Nothing about the platform being correct
 * depends on it: eligibility is decided by the order's status when somebody
 * actually submits, so a message lost to a broken queue costs a review, never
 * a rule.
 */
class InviteRatingsOnOrderCompleted implements ShouldQueue
{
    public function handle(OrderCompleted $event): void
    {
        $order = $event->order->loadMissing(['buyer', 'seller.user']);

        $order->buyer->notify(new RatingInvitation(
            $order,
            $order->seller->business_name,
            forSeller: false,
        ));

        $order->seller->user->notify(new RatingInvitation(
            $order,
            $order->buyer->name,
            forSeller: true,
        ));
    }
}
