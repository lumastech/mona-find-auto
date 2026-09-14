<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * The ways the gateway and the books can disagree.
 *
 * Ordered by how alarming they are. `Missing` is the one that costs a buyer
 * their money and a seller their sale; `Unsettled` is usually just Lenco's
 * next-day timetable and only matters if it persists.
 */
enum ReconciliationExceptionType: string
{
    /** Lenco collected money we have no Payment row for. */
    case Missing = 'missing';

    /** Both sides know the payment, for different amounts. */
    case AmountMismatch = 'amount_mismatch';

    /** We think a payment succeeded; Lenco has never heard of it. */
    case Orphan = 'orphan';

    /** Collected days ago and still not settled to our account. */
    case Unsettled = 'unsettled';

    /** The day's platform_cash movement does not match the gateway statement. */
    case LedgerVariance = 'ledger_variance';

    public function severity(): string
    {
        return match ($this) {
            self::Missing, self::Orphan, self::LedgerVariance => 'critical',
            self::AmountMismatch => 'error',
            self::Unsettled => 'warning',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Missing payment record',
            self::AmountMismatch => 'Amount mismatch',
            self::Orphan => 'Orphan payment',
            self::Unsettled => 'Not settled',
            self::LedgerVariance => 'Ledger variance',
        };
    }
}
