/**
 * Shapes owned by the Ledger module: the double-entry ledger, the terms
 * sellers trade on, and the manual corrections staff post under dual control.
 *
 * Every amount is integer ngwee, as everywhere else — render with <Money />.
 */

export type LedgerAccountValue =
    | 'platform_cash'
    | 'escrow_held'
    | 'seller_payable'
    | 'seller_reserve'
    | 'commission_revenue'
    | 'addon_revenue'
    | 'referral_revenue'
    | 'vat_on_commission_payable'
    | 'refunds_expense'
    | 'lenco_fees_expense';

export type LedgerAccountTypeValue =
    | 'asset'
    | 'liability'
    | 'revenue'
    | 'expense';

export type EntryDirectionValue = 'debit' | 'credit';

export type PostingRecipeValue =
    | 'escrow_payment'
    | 'escrow_release'
    | 'direct_payment'
    | 'escrow_refund'
    | 'direct_clawback'
    | 'goodwill_refund'
    | 'payout'
    | 'reserve_release'
    | 'manual_adjustment';

export type AdjustmentStatusValue = 'pending' | 'approved' | 'rejected';

export type CommissionTypeValue = 'percentage' | 'flat';

/** One account with its current balance, as the browser header shows it. */
export type LedgerAccountSummary = {
    value: LedgerAccountValue;
    label: string;
    type: LedgerAccountTypeValue;
    description: string;
    /** Signed against the account's normal balance: positive means "as expected". */
    balance_ngwee: number;
};

/** An account as the adjustment form offers it. */
export type LedgerAccountOption = {
    value: LedgerAccountValue;
    label: string;
    type: LedgerAccountTypeValue;
    normal_balance: EntryDirectionValue;
    /** 'none', 'order' or 'seller' — whether a line must name a subject. */
    subject: 'none' | 'order' | 'seller';
    description: string;
    manually_adjustable: boolean;
};

export type JournalLineRow = {
    account: LedgerAccountValue;
    account_label: string;
    direction: EntryDirectionValue;
    amount_ngwee: number;
    /** 'Order' or 'Seller' — the class, not the namespace. */
    subject_type: string | null;
    subject_id: number | null;
    /** The subject's route key: an order number, a seller slug. */
    subject_label: string | null;
    memo: string | null;
};

export type JournalEntryRow = {
    uuid: string;
    recipe: PostingRecipeValue;
    recipe_label: string;
    description: string;
    total_ngwee: number;
    actor_label: string;
    reference_label: string | null;
    reference_type: string | null;
    reference_id: number | null;
    /** The business event this entry records. One event, one entry, forever. */
    idempotency_key: string;
    context: Record<string, unknown> | null;
    posted_at: string;
    lines?: JournalLineRow[];
};

export type MonetisationPolicyRow = {
    slug: string;
    name: string;
    description: string | null;
    commission_type: CommissionTypeValue;
    commission_percent: string;
    commission_flat_ngwee: number;
    commission_description: string;
    addon_fee_ngwee: number;
    referral_fee_percent: string;
    is_default: boolean;
    is_active: boolean;
    seller_count?: number;
    created_at: string | null;
};

export type SellerPolicyRow = {
    id: number;
    slug: string;
    business_name: string;
    payment_mode: string;
    payment_mode_label: string;
    policy_slug: string | null;
    policy_name: string;
    on_default: boolean;
    payable_ngwee: number;
    reserve_ngwee: number;
};

export type AdjustmentLineRow = {
    account: LedgerAccountValue;
    account_label: string;
    direction: EntryDirectionValue;
    amount_ngwee: number;
    subject_type: string | null;
    subject_id: number | null;
    memo: string | null;
};

export type LedgerAdjustmentRow = {
    id: number;
    status: AdjustmentStatusValue;
    status_label: string;
    description: string;
    reason: string;
    lines: AdjustmentLineRow[];
    total_ngwee: number;
    author: string | null;
    decider: string | null;
    decided_at: string | null;
    decision_note: string | null;
    journal_entry_uuid: string | null;
    created_at: string | null;
    /**
     * Whether the person looking may decide it — not a property of the
     * adjustment, since the dual-control rule turns on who is asking.
     */
    can_decide: boolean;
};
