<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * How a seller's money reaches them.
 *
 * The mode is set per seller by MonaFind staff and is snapshotted onto every
 * order at payment time, so changing it never disturbs money already in
 * flight. Sellers can see which mode they are on; they can never set it.
 */
enum PaymentMode: string
{
    /** The platform holds the buyer's money until the order completes. */
    case Escrow = 'escrow';

    /** The seller is paid out on payment, against a rolling reserve. */
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::Escrow => 'Escrow',
            self::Direct => 'Direct',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Escrow => 'MonaFind holds each payment until the buyer confirms the part, or the confirmation window closes.',
            self::Direct => 'Payments are released to you as orders are paid, less a rolling reserve held against disputes.',
        };
    }

    /**
     * Whether the platform withholds a rolling reserve on this mode.
     */
    public function carriesReserve(): bool
    {
        return $this === self::Direct;
    }

    /**
     * The mode new sellers start on, from platform settings.
     */
    public static function default(): self
    {
        $configured = settings('escrow.default_payment_mode');

        return is_string($configured) ? (self::tryFrom($configured) ?? self::Escrow) : self::Escrow;
    }
}
