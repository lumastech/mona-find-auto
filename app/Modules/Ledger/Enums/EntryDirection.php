<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * Which side of the ledger a line sits on.
 *
 * Every journal line carries a positive amount and one of these. Signing the
 * amount instead — a negative credit meaning a debit — is the shortcut that
 * makes a ledger unreadable six months later, and it makes "lines balance to
 * zero" a check that a typo can pass.
 */
enum EntryDirection: string
{
    case Debit = 'debit';

    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Debit => self::Credit,
            self::Credit => self::Debit,
        };
    }

    /**
     * The sign this direction contributes to an account whose normal balance
     * is $normal. A debit on a debit-normal account increases it; a debit on
     * a credit-normal account reduces it.
     */
    public function signAgainst(self $normal): int
    {
        return $this === $normal ? 1 : -1;
    }
}
