<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Models\Order;
use RuntimeException;

/**
 * The buyer cannot raise a problem with this order right now.
 *
 * Two cases, and they read very differently to a buyer: one already open, or
 * an order that has finished. The second is the one worth wording carefully —
 * "too late" is true but useless, so it points at support instead.
 */
class DisputeNotAllowed extends RuntimeException
{
    public static function alreadyOpen(Order $order): self
    {
        return new self(sprintf('There is already an open dispute on order %s.', $order->number));
    }

    public static function orderFinished(Order $order): self
    {
        return new self(sprintf(
            'Order %s has already completed. Contact MonaFind support if there is still a problem.',
            $order->number,
        ));
    }

    public static function notPaid(Order $order): self
    {
        return new self(sprintf('Order %s has not been paid for.', $order->number));
    }

    public static function alreadyResolved(Order $order): self
    {
        return new self(sprintf('The dispute on order %s has already been resolved.', $order->number));
    }
}
