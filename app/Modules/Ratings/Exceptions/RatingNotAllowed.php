<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Exceptions;

use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use RuntimeException;

/**
 * This person cannot leave this rating.
 *
 * Four distinct refusals, kept apart because they need four different
 * sentences. "You have already reviewed this order" is a fact the buyer can
 * act on; "not allowed" is not.
 */
class RatingNotAllowed extends RuntimeException
{
    /**
     * The order has not completed, so nothing has been proven about either
     * party yet. This is the guard the whole module rests on: a review before
     * completion is a review of an expectation.
     */
    public static function orderNotCompleted(Order $order): self
    {
        return new self(sprintf(
            'Order %s has not completed yet. You can leave a review once the order is finished.',
            $order->number,
        ));
    }

    public static function sourceNotCompleted(): self
    {
        return new self('This can only be rated once it has finished.');
    }

    public static function alreadyRated(RatingDirection $direction): self
    {
        return new self(match ($direction->isPublic()) {
            true => 'You have already reviewed this.',
            false => 'You have already rated this buyer.',
        });
    }

    public static function notYours(): self
    {
        return new self('You were not part of this, so you cannot rate it.');
    }

    public static function directionUnavailable(RatingDirection $direction): self
    {
        return new self(sprintf('A "%s" rating cannot be left here.', $direction->label()));
    }
}
