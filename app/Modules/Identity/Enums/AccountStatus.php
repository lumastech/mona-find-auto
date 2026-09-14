<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * The lifecycle of a MonaFind account.
 *
 * Only Active accounts may act on the platform. EnsureAccountIsActive turns
 * every other state into a block, and suspension additionally tears down the
 * account's live sessions and API tokens.
 */
enum AccountStatus: string
{
    /** Registered but the phone number has not been verified yet. */
    case Pending = 'pending';

    /** In good standing. */
    case Active = 'active';

    /** Blocked by staff, with a recorded reason. Reversible. */
    case Suspended = 'suspended';

    /** Closed by staff or at the account holder's request. Terminal. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /**
     * Whether the account may authenticate and act.
     */
    public function permitsAccess(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether an existing session must be torn down when an account lands
     * in this state.
     */
    public function forcesLogout(): bool
    {
        return $this === self::Suspended || $this === self::Closed;
    }

    /**
     * The message shown to somebody who is blocked in this state.
     */
    public function blockedMessage(?string $reason = null): string
    {
        $message = match ($this) {
            self::Pending => 'Verify your phone number before continuing.',
            self::Active => 'Your account is active.',
            self::Suspended => 'Your account is suspended.',
            self::Closed => 'Your account has been closed.',
        };

        return $reason === null || $reason === ''
            ? $message
            : $message.' Reason: '.$reason;
    }
}
