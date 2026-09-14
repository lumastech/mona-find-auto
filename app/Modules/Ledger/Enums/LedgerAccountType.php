<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * The classification of a ledger account, which is what decides the side its
 * balance is read from.
 *
 * MonaFind's chart is small enough that it needs no equity or income-summary
 * accounts: the platform holds other people's money (liabilities), the cash
 * it sits in (an asset), what it earns (revenue) and what it spends getting
 * money in and out (expenses).
 */
enum LedgerAccountType: string
{
    case Asset = 'asset';

    case Liability = 'liability';

    case Revenue = 'revenue';

    case Expense = 'expense';

    /**
     * The direction that increases an account of this type.
     */
    public function normalBalance(): EntryDirection
    {
        return match ($this) {
            self::Asset, self::Expense => EntryDirection::Debit,
            self::Liability, self::Revenue => EntryDirection::Credit,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Revenue => 'Revenue',
            self::Expense => 'Expense',
        };
    }
}
