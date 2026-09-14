<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * MonaFind's chart of accounts, exactly as CLAUDE.md fixes it.
 *
 * The enum is the source of truth and the `ledger_accounts` table is seeded
 * from it, so an account cannot be invented by inserting a row: a posting
 * recipe naming an account that is not on this list is a compile-time
 * problem rather than a reconciliation problem months later.
 *
 * Read the list as a story about whose money it is. A buyer pays into
 * platform_cash, which is Lenco's balance and MonaFind's asset. What arrives
 * is not MonaFind's to spend: it is either escrow_held (owed back to the
 * buyer or forward to the seller, undecided) or seller_payable (owed to the
 * seller now), less the parts the platform has actually earned —
 * commission_revenue, addon_revenue, referral_revenue — and the VAT on the
 * commission, which is owed to ZRA and never was the platform's.
 */
enum LedgerAccountCode: string
{
    /** The platform's balance at Lenco. Every ngwee in and out crosses here. */
    case PlatformCash = 'platform_cash';

    /** Buyer money the platform is holding until an order settles. */
    case EscrowHeld = 'escrow_held';

    /** What is owed to a seller and not yet paid out. */
    case SellerPayable = 'seller_payable';

    /** The rolling reserve withheld from direct-payment sellers. */
    case SellerReserve = 'seller_reserve';

    /** MonaFind's commission, once earned. */
    case CommissionRevenue = 'commission_revenue';

    /** Per-order add-on fees. */
    case AddonRevenue = 'addon_revenue';

    /** Fees on orders that arrived through a referral partner. */
    case ReferralRevenue = 'referral_revenue';

    /** VAT charged on MonaFind's commission, owed onward to ZRA. */
    case VatOnCommissionPayable = 'vat_on_commission_payable';

    /** Refunds the platform funded itself rather than recovering. */
    case RefundsExpense = 'refunds_expense';

    /** What Lenco charges to move the money. */
    case LencoFeesExpense = 'lenco_fees_expense';

    public function type(): LedgerAccountType
    {
        return match ($this) {
            self::PlatformCash => LedgerAccountType::Asset,
            self::EscrowHeld,
            self::SellerPayable,
            self::SellerReserve,
            self::VatOnCommissionPayable => LedgerAccountType::Liability,
            self::CommissionRevenue,
            self::AddonRevenue,
            self::ReferralRevenue => LedgerAccountType::Revenue,
            self::RefundsExpense,
            self::LencoFeesExpense => LedgerAccountType::Expense,
        };
    }

    public function normalBalance(): EntryDirection
    {
        return $this->type()->normalBalance();
    }

    /**
     * What this account's balance is broken down by.
     */
    public function subject(): LedgerSubject
    {
        return match ($this) {
            self::EscrowHeld => LedgerSubject::Order,
            self::SellerPayable, self::SellerReserve => LedgerSubject::Seller,
            default => LedgerSubject::None,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PlatformCash => 'Platform cash (Lenco)',
            self::EscrowHeld => 'Escrow held',
            self::SellerPayable => 'Seller payable',
            self::SellerReserve => 'Seller reserve',
            self::CommissionRevenue => 'Commission revenue',
            self::AddonRevenue => 'Add-on revenue',
            self::ReferralRevenue => 'Referral revenue',
            self::VatOnCommissionPayable => 'VAT on commission payable',
            self::RefundsExpense => 'Refunds expense',
            self::LencoFeesExpense => 'Lenco fees',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PlatformCash => 'The platform\'s balance at Lenco. Every collection, refund and payout crosses this account.',
            self::EscrowHeld => 'Buyer money held against an order that has not yet completed. Owed back to the buyer or forward to the seller.',
            self::SellerPayable => 'Money owed to a seller and not yet paid out. Goes negative when a refund is clawed back from a direct-payment seller.',
            self::SellerReserve => 'The rolling reserve withheld from a direct-payment seller, released on schedule.',
            self::CommissionRevenue => 'MonaFind\'s commission on completed sales, recognised when the money is released.',
            self::AddonRevenue => 'Per-order add-on fees charged under the seller\'s monetisation policy.',
            self::ReferralRevenue => 'Fees on orders introduced by a referral partner.',
            self::VatOnCommissionPayable => 'VAT charged on MonaFind\'s commission, owed onward to ZRA. Never the platform\'s money.',
            self::RefundsExpense => 'Refunds the platform absorbed rather than recovering from escrow or a seller.',
            self::LencoFeesExpense => 'Gateway charges on collections and payouts.',
        };
    }

    /**
     * Whether staff may name this account on a manual adjustment.
     *
     * Cash is excluded: money does not enter or leave Lenco because somebody
     * typed a journal entry, and an adjustment that says it did puts the
     * ledger permanently out of step with the bank.
     */
    public function isManuallyAdjustable(): bool
    {
        return $this !== self::PlatformCash;
    }

    /**
     * @return array<int, array{value: string, label: string, type: string, normal_balance: string, subject: string, description: string, manually_adjustable: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $code): array => [
            'value' => $code->value,
            'label' => $code->label(),
            'type' => $code->type()->value,
            'normal_balance' => $code->normalBalance()->value,
            'subject' => $code->subject()->value,
            'description' => $code->description(),
            'manually_adjustable' => $code->isManuallyAdjustable(),
        ], self::cases());
    }
}
