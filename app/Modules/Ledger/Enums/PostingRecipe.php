<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * Why a journal entry exists.
 *
 * Every entry names the recipe that produced it, and the list is closed: if
 * money moved for a reason that is not here, the movement had no recipe and
 * should not have been posted. It is what the ledger browser filters on and
 * what makes a month's cash movement explainable without reading each entry's
 * lines.
 */
enum PostingRecipe: string
{
    /** A buyer paid; the money is held. */
    case EscrowPayment = 'escrow_payment';

    /** The order completed; escrow becomes payable and revenue. */
    case EscrowRelease = 'escrow_release';

    /** A buyer paid a direct-settlement seller; payable and reserve at once. */
    case DirectPayment = 'direct_payment';

    /** Money returned to a buyer out of what was held for their order. */
    case EscrowRefund = 'escrow_refund';

    /** Money returned to a buyer and recovered from a seller already credited. */
    case DirectClawback = 'direct_clawback';

    /** Money returned to a buyer at the platform's own expense. */
    case GoodwillRefund = 'goodwill_refund';

    /** A seller was paid out. */
    case Payout = 'payout';

    /** A held reserve became payable again. */
    case ReserveRelease = 'reserve_release';

    /** A staff-authored correction, created by finance and approved by an admin. */
    case ManualAdjustment = 'manual_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::EscrowPayment => 'Escrow payment',
            self::EscrowRelease => 'Escrow release',
            self::DirectPayment => 'Direct payment',
            self::EscrowRefund => 'Escrow refund',
            self::DirectClawback => 'Direct clawback',
            self::GoodwillRefund => 'Goodwill refund',
            self::Payout => 'Payout',
            self::ReserveRelease => 'Reserve release',
            self::ManualAdjustment => 'Manual adjustment',
        };
    }

    /**
     * Whether this recipe recognises MonaFind's fees as revenue.
     *
     * The moment a commission invoice belongs to. Escrow payments do not:
     * money merely arriving is not money earned, and an order refunded the
     * next day never earned anything.
     */
    public function recognisesRevenue(): bool
    {
        return match ($this) {
            self::EscrowRelease, self::DirectPayment => true,
            default => false,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $recipe): array => [
            'value' => $recipe->value,
            'label' => $recipe->label(),
        ], self::cases());
    }
}
