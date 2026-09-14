<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Listeners;

use App\Models\User;
use App\Modules\Ratings\Events\RatingSubmitted;
use App\Modules\Ratings\Notifications\RatingReceived;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell the person or shop a review is about.
 *
 * Public directions only, and that restriction is the point rather than an
 * optimisation. Seller→Buyer and Mechanic→Buyer ratings are visible to
 * sellers and staff and NOT to the buyer they are about — otherwise a buyer
 * learns to trade a bad review for a good rating. Telling the buyer that a
 * private rating of them exists would defeat that rule just as thoroughly as
 * showing them the stars, so those directions notify nobody.
 *
 * A rating held for moderation is not announced either. The ratee finding out
 * about a review that has not been published, and cannot yet be replied to,
 * is an invitation to chase it.
 */
class NotifyRateeOfRating implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(RatingSubmitted $event): void
    {
        $rating = $event->rating;

        if (! $rating->isPubliclyVisible()) {
            return;
        }

        $ratee = $rating->ratee;

        $user = match (true) {
            $ratee instanceof Seller => $ratee->user,
            $ratee instanceof User => $ratee,
            default => null,
        };

        $user?->notify(new RatingReceived($rating));
    }
}
