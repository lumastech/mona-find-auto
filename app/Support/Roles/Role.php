<?php

declare(strict_types=1);

namespace App\Support\Roles;

/**
 * The platform's roles, backed by spatie/laravel-permission.
 *
 * The Identity module seeds these; middleware and policies refer to them
 * through this enum rather than by string so a rename stays a compile error.
 *
 * One account may hold several roles at once — being a buyer and a mechanic
 * is the common case.
 */
enum Role: string
{
    /** Anyone who can place an order. Every registered account has this. */
    case Buyer = 'buyer';

    /** Runs a verified seller account: shop, garage, breaker, dealer. */
    case Seller = 'seller';

    /** Additional staff on a seller account. */
    case SellerStaff = 'seller-staff';

    /** Holds an endorsed public mechanic profile. */
    case Mechanic = 'mechanic';

    /** MonaFind staff: seller verification, listing moderation, disputes. */
    case Moderator = 'moderator';

    /** MonaFind staff: payouts, refunds, reconciliation, the ledger. */
    case Finance = 'finance';

    /** Full access, including money movement and platform settings. */
    case PlatformAdmin = 'platform-admin';

    /**
     * Roles that may open the seller portal.
     *
     * @return array<int, string>
     */
    public static function sellerPortal(): array
    {
        return [self::Seller->value, self::SellerStaff->value];
    }

    /**
     * Roles that may open the staff console.
     *
     * @return array<int, string>
     */
    public static function staffConsole(): array
    {
        return [self::Moderator->value, self::Finance->value, self::PlatformAdmin->value];
    }

    /**
     * Roles for which two-factor authentication is mandatory.
     *
     * These accounts can move money, unpublish listings and change other
     * people's accounts, so a password alone is never enough.
     *
     * @return array<int, string>
     */
    public static function requiringTwoFactor(): array
    {
        return self::staffConsole();
    }

    /**
     * Roles a person can only be given by MonaFind staff.
     *
     * @return array<int, string>
     */
    public static function staffAssignable(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            [self::Seller, self::SellerStaff, self::Mechanic, self::Moderator, self::Finance, self::PlatformAdmin],
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Seller => 'Seller',
            self::SellerStaff => 'Seller staff',
            self::Mechanic => 'Mechanic',
            self::Moderator => 'Moderator',
            self::Finance => 'Finance',
            self::PlatformAdmin => 'Platform administrator',
        };
    }
}
