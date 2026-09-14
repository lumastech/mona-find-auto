<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use RuntimeException;

/**
 * The right move, by the wrong party.
 *
 * Kept apart from InvalidOrderTransition because the two mean different
 * things to whoever is watching: one says this order cannot go there at all,
 * the other says it can, but not on your say-so. The guard that produces this
 * most often is the one stopping a seller completing their own order.
 */
class UnauthorisedOrderAction extends RuntimeException
{
    public static function for(Order $order, OrderStatus $to, OrderActorType $actor): self
    {
        return new self(sprintf(
            'A %s may not move order %s to %s.',
            $actor->value,
            $order->number,
            $to->value,
        ));
    }
}
