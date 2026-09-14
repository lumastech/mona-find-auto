<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

/**
 * Where a staff invitation stands.
 *
 * Derived from the timestamps on the row rather than stored, so an invitation
 * cannot claim to be pending after its expiry has passed.
 */
enum StaffInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting acceptance',
            self::Accepted => 'Accepted',
            self::Revoked => 'Revoked',
            self::Expired => 'Expired',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Accepted => 'default',
            self::Pending => 'secondary',
            self::Revoked, self::Expired => 'destructive',
        };
    }
}
