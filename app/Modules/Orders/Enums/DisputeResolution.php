<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\Money\Money;

/**
 * How a moderator ended a dispute.
 *
 * Three outcomes, and each one is an instruction to the money. Payments
 * consumes DisputeResolved and acts on exactly this value, which is why the
 * list is closed and why a partial refund carries its amount: "resolved in
 * the buyer's favour" is not something a ledger can post.
 */
enum DisputeResolution: string
{
    /** The seller was right. Escrow releases to them as though nothing happened. */
    case Release = 'release';

    /** Some of the money goes back; the rest is the seller's. */
    case PartialRefund = 'partial_refund';

    /** All of it goes back to the buyer. */
    case FullRefund = 'full_refund';

    public function label(): string
    {
        return match ($this) {
            self::Release => 'Release to seller',
            self::PartialRefund => 'Partial refund',
            self::FullRefund => 'Full refund',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Release => 'The order completes and the seller is paid in full.',
            self::PartialRefund => 'Part of the order value goes back to the buyer; the order completes.',
            self::FullRefund => 'The whole order value goes back to the buyer and the stock returns to the seller.',
        };
    }

    /**
     * Whether this outcome needs an amount naming.
     */
    public function needsAmount(): bool
    {
        return $this === self::PartialRefund;
    }

    /**
     * Where the order lands once the resolution is applied.
     *
     * A partial refund still completes: the buyer kept the part and the
     * seller keeps most of the money. Only a full refund unwinds the sale,
     * which is what puts the stock back.
     */
    public function resultingStatus(): OrderStatus
    {
        return match ($this) {
            self::Release, self::PartialRefund => OrderStatus::Completed,
            self::FullRefund => OrderStatus::Refunded,
        };
    }

    /**
     * How much goes back to the buyer under this resolution.
     */
    public function refundAmount(Money $orderTotal, ?Money $requested = null): Money
    {
        return match ($this) {
            self::Release => Money::zero(),
            self::PartialRefund => $requested ?? Money::zero(),
            self::FullRefund => $orderTotal,
        };
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, needs_amount: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $resolution): array => [
            'value' => $resolution->value,
            'label' => $resolution->label(),
            'description' => $resolution->description(),
            'needs_amount' => $resolution->needsAmount(),
        ], self::cases());
    }
}
