<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * How a monetisation policy charges commission.
 *
 * The string values match what MonetisationSnapshot has always written into
 * `orders.monetisation_snapshot`, so snapshots taken before this module
 * existed still read back correctly.
 */
enum CommissionType: string
{
    /** A share of the goods value. */
    case Percentage = 'percentage';

    /** The same amount on every order, whatever it was worth. */
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of order value',
            self::Flat => 'Flat fee per order',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
        ], self::cases());
    }
}
