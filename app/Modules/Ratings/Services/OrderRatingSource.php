<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Contracts\RatingSourceResolver;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingSource;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Support\RatingParties;
use App\Modules\Ratings\Support\RatingPrompt;
use Illuminate\Database\Eloquent\Model;

/**
 * A completed order, as something to rate.
 *
 * One order, two directions: the buyer reviews the shop in public, and the
 * shop rates the buyer in private. Both are earned by the same thing —
 * OrderStatus::Completed — and neither is available a moment before it.
 *
 * "Completed" specifically, not "delivered" or "collected". Completion is the
 * point at which the buyer has confirmed, or the checking window has closed
 * with no dispute, and the money has become the seller's. Anything earlier is
 * a review of an expectation.
 */
class OrderRatingSource implements RatingSourceResolver
{
    /**
     * @return class-string<Model>
     */
    public function handles(): string
    {
        return Order::class;
    }

    public function source(): RatingSource
    {
        return RatingSource::Order;
    }

    public function isComplete(Model $source): bool
    {
        return $source instanceof Order && $source->status === OrderStatus::Completed;
    }

    /**
     * @return array<int, RatingPrompt>
     */
    public function prompts(Model $source, User $user): array
    {
        if (! $source instanceof Order || ! $this->isComplete($source)) {
            return [];
        }

        $prompts = [];

        if ($source->belongsToBuyer($user)) {
            $prompts[] = new RatingPrompt(
                direction: RatingDirection::BuyerToSeller,
                source: $source,
                ratee: $source->seller,
                rateeName: $source->seller->business_name,
                heading: 'How was '.$source->seller->business_name.'?',
            );
        }

        if ($source->belongsToSellerOf($user)) {
            $prompts[] = new RatingPrompt(
                direction: RatingDirection::SellerToBuyer,
                source: $source,
                ratee: $source->buyer,
                rateeName: $source->buyer->name,
                heading: 'How was dealing with '.$source->buyer->name.'?',
            );
        }

        return $prompts;
    }

    public function parties(Model $source, RatingDirection $direction, User $user): RatingParties
    {
        if (! $source instanceof Order) {
            throw RatingNotAllowed::directionUnavailable($direction);
        }

        return match ($direction) {
            /* The buyer rates as themselves. */
            RatingDirection::BuyerToSeller => $this->buyerRatingSeller($source, $user),

            /*
             * The shop rates as the business, and the member of staff who
             * typed it is recorded separately: a buyer who deals with a shop
             * twice should see one reputation, not one per counter hand.
             */
            RatingDirection::SellerToBuyer => $this->sellerRatingBuyer($source, $user),

            default => throw RatingNotAllowed::directionUnavailable($direction),
        };
    }

    private function buyerRatingSeller(Order $order, User $user): RatingParties
    {
        if (! $order->belongsToBuyer($user)) {
            throw RatingNotAllowed::notYours();
        }

        return new RatingParties(
            rater: $user,
            ratee: $order->seller,
            submittedBy: $user,
            rateeName: $order->seller->business_name,
        );
    }

    private function sellerRatingBuyer(Order $order, User $user): RatingParties
    {
        if (! $order->belongsToSellerOf($user)) {
            throw RatingNotAllowed::notYours();
        }

        return new RatingParties(
            rater: $order->seller,
            ratee: $order->buyer,
            submittedBy: $user,
            rateeName: $order->buyer->name,
        );
    }
}
