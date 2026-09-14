<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use RuntimeException;

/**
 * A move the order's lifecycle does not allow.
 *
 * Thrown rather than returned false, because every caller that reaches the
 * state machine has already decided the move should happen — a seller pressed
 * "Dispatch", a timer fired. A silent no-op there is an order that quietly
 * stops progressing and a seller who thinks they dispatched something.
 */
class InvalidOrderTransition extends RuntimeException
{
    public static function between(Order $order, OrderStatus $to): self
    {
        return new self(sprintf(
            'Order %s cannot move from %s to %s.',
            $order->number,
            $order->status->value,
            $to->value,
        ));
    }
}
