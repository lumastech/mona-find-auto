<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * Which side of a conversation somebody is on.
 *
 * Not a permission — membership is the permission, and every participant may
 * read and write. This is what the thread header says ("You · Mwansa Auto
 * Spares") and what lets a seller inbox show the customer's name rather than
 * their own.
 *
 * Staff are absent on purpose. A moderator reading a disputed thread is not a
 * participant; see MessageThreadPolicy.
 */
enum ThreadRole: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Mechanic = 'mechanic';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Seller => 'Seller',
            self::Mechanic => 'Mechanic',
        };
    }

    /**
     * Whether this side answers rather than asks — the half a seller inbox
     * is built around.
     */
    public function isTrader(): bool
    {
        return $this !== self::Buyer;
    }
}
