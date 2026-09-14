<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Events;

use App\Modules\Ratings\Models\Rating;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody rated somebody.
 *
 * Fired whether the review went straight onto the page or into the queue,
 * because a rating in the queue still counts towards the subject's trust
 * score — see RatingStatus. Listeners that care about publication should
 * check the status rather than waiting for a different event.
 */
class RatingSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Rating $rating) {}
}
