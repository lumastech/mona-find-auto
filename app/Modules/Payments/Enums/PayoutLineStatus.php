<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * One seller's leg of a payout run.
 *
 * The distinction that matters is Failed against Unresolved. Failed means
 * Lenco told us the transfer did not happen, so the seller's payable is put
 * back and they are paid on the next run. Unresolved means we do not know —
 * the call timed out, the gateway was unreachable — and the payable is NOT
 * reverted, because reverting money that did leave pays the seller twice.
 * Unresolved lines are a Finance queue, not an automatic anything.
 */
enum PayoutLineStatus: string
{
    case Pending = 'pending';
    case Blocked = 'blocked';
    case Sent = 'sent';
    case Paid = 'paid';
    case Failed = 'failed';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Blocked => 'Blocked',
            self::Sent => 'Sent',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Unresolved => 'Needs checking',
        };
    }

    /**
     * Whether this outcome puts the seller's payable back.
     *
     * Blocked lines never left, so they revert too. Unresolved deliberately
     * does not: see the enum docblock.
     */
    public function revertsPayable(): bool
    {
        return $this === self::Failed || $this === self::Blocked;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Blocked], true);
    }

    /** Whether a human has to look at this line. */
    public function needsAttention(): bool
    {
        return $this === self::Blocked || $this === self::Unresolved || $this === self::Failed;
    }
}
