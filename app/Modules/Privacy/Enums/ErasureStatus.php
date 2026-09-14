<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Enums;

/**
 * Where an account-deletion request has got to.
 *
 * There is a waiting period between asking and erasing, and this enum is what
 * tracks it. The reason for the delay is in ErasureRequest.
 */
enum ErasureStatus: string
{
    /** Asked for, inside the grace period, still reversible by the account holder. */
    case Pending = 'pending';

    /** Held by staff because something has to settle first — an open order, a live dispute. */
    case Blocked = 'blocked';

    /** The account holder changed their mind inside the grace period. */
    case Cancelled = 'cancelled';

    /** Done. The account row survives as a tombstone; the personal data does not. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Scheduled',
            self::Blocked => 'On hold',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    /**
     * Is this request still going to happen?
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Blocked], true);
    }

    /**
     * May the account holder still call it off themselves?
     */
    public function isCancellable(): bool
    {
        return $this === self::Pending;
    }
}
