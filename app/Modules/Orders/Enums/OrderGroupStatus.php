<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * Where the payment covering a whole cart stands.
 *
 * An order group exists because a cart spanning four shops is still one thing
 * the buyer pays for. It has a much shorter life than the orders inside it:
 * once the money has arrived the group has done its job, and everything that
 * happens afterwards — confirming, dispatching, disputing — happens to each
 * seller's order on its own timetable.
 */
enum OrderGroupStatus: string
{
    /** Placed; the buyer has not paid, or the payment has not landed. */
    case PendingPayment = 'pending_payment';

    /** The money arrived and every order inside was released to its seller. */
    case Paid = 'paid';

    /** Abandoned or called off before payment. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }
}
