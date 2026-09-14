<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The buyer cannot pay for this group right now, and why.
 *
 * Everything here is something the buyer can act on or at least understand,
 * so controllers turn these into a sentence on the page rather than a 500.
 */
class PaymentUnavailable extends RuntimeException
{
    public static function alreadyPaid(): self
    {
        return new self(__('This order has already been paid for.'));
    }

    public static function notPayable(): self
    {
        return new self(__('This order can no longer be paid for.'));
    }

    public static function nothingToPay(): self
    {
        return new self(__('There is nothing to pay on this order.'));
    }

    public static function notConfigured(): self
    {
        return new self(__('Payments are not available at the moment. Please try again shortly.'));
    }

    public static function unknownReference(): self
    {
        return new self(__('We could not find that payment.'));
    }
}
