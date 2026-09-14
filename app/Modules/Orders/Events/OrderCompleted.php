<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The order is finished and the money is the seller's.
 *
 * This is the event escrow release hangs on, which is why it is fired from
 * exactly two places: a buyer confirming receipt, and the auto-completion
 * sweep when the checking window closes with no dispute. A seller can never
 * cause it — see OrderStatus::actorsAllowedToEnter() — because a seller who
 * could complete their own order could pay themselves.
 *
 * Ratings listens too: a completed order is what entitles each side to rate
 * the other, exactly once.
 *
 * @param  bool  $automatic  True when a timer completed it rather than the buyer.
 *                           Worth carrying: an order nobody confirmed is weaker
 *                           evidence of a happy buyer than one they did.
 */
class OrderCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public bool $automatic = false,
    ) {}
}
