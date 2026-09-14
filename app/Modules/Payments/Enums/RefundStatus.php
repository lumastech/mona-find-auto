<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Where a refund has got to.
 *
 * `Manual` is a resting state, not a failure: the refund is decided, posted
 * and owed, and a Finance human has to push it through Lenco's card process.
 * It sits there until someone marks it completed.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Manual = 'manual';
    case Sent = 'sent';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Manual => 'Awaiting Finance',
            self::Sent => 'Sent',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /** Whether this refund is sitting in the Finance task queue. */
    public function needsAttention(): bool
    {
        return $this === self::Manual || $this === self::Failed;
    }
}
