<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * Who moved an order.
 *
 * Recorded on every status event alongside the user id, and checked against
 * OrderStatus::actorsAllowedToEnter() before any move is allowed. The
 * distinction that earns its keep is System: a great many of the transitions
 * on this platform have no person behind them at all — a payment webhook
 * arriving, a confirmation window closing — and a timeline that attributed
 * those to whoever happened to be logged in would be evidence of the wrong
 * thing in a dispute.
 */
enum OrderActorType: string
{
    /** The buyer who placed the order. */
    case Buyer = 'buyer';

    /** The seller the order belongs to, or their staff. */
    case Seller = 'seller';

    /** MonaFind staff: a moderator, usually resolving something. */
    case Staff = 'staff';

    /** Nobody. A timer, a webhook, a scheduled sweep. */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Seller => 'Seller',
            self::Staff => 'MonaFind',
            self::System => 'Automatic',
        };
    }

    /**
     * Whether a real person is behind this actor, and so whether the status
     * event should carry a user id.
     */
    public function isPerson(): bool
    {
        return $this !== self::System;
    }
}
