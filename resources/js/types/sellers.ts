/**
 * Shapes owned by the Sellers module: businesses, their policies, their
 * payout accounts and the verification workflow.
 */

export type SellerTypeCode = 'APS' | 'SPS' | 'G' | 'CB' | 'AMR' | 'WO' | 'CD';

export type VerificationStatusValue =
    | 'draft'
    | 'submitted'
    | 'under_review'
    | 'inspection_scheduled'
    | 'verified'
    | 'rejected'
    | 'suspended';

export type PolicyTypeValue = 'delivery' | 'refund' | 'warranty' | 'terms';

export type PayoutMethodValue = 'bank' | 'mobile_money';

export type RegistrationStepValue =
    | 'type'
    | 'business'
    | 'policies'
    | 'payout'
    | 'documents'
    | 'review';

export type SellerTypeOption = {
    value: SellerTypeCode;
    label: string;
    description: string;
    /** Listings from this seller are badged Car Breaker automatically. */
    breaker_stock: boolean;
};

export type PolicyTypeOption = {
    value: PolicyTypeValue;
    label: string;
    guidance: string;
    required: boolean;
    /** True for the refund policy, which sits beside the platform floor. */
    shows_platform_minimum: boolean;
};

export type RegistrationStepOption = {
    value: RegistrationStepValue;
    label: string;
    position: number;
};

export type BankOption = { code: string; name: string };

export type LabelledOption = { value: string; label: string };

/**
 * One contact field on a public seller page.
 *
 * For a guest the value is already masked by the server — there is no real
 * value in the payload to blur, which is the point.
 */
export type SellerContactField = {
    key: 'phone' | 'email' | 'contact_person';
    label: string;
    value: string;
};

export type SellerContact = {
    visible: boolean;
    /** "Log in to view", or empty when the values are real. */
    prompt: string;
    fields: SellerContactField[];
};

export type SellerPolicy = {
    id: number;
    type: PolicyTypeValue;
    type_label: string;
    version: number;
    body: string;
    excerpt: string;
    effective_from: string;
    in_force: boolean;
    is_current: boolean;
    /** True for the refund policy, which sits beside the platform floor. */
    shows_platform_minimum: boolean;
    created_at: string | null;
};

export type PlatformMinimumRefund = {
    days: number;
    statement: string;
};

export type PayoutAccount = {
    id: number;
    method: PayoutMethodValue;
    method_label: string;
    label: string | null;
    display_name: string;
    /** Never the full number, even to the seller who typed it. */
    masked_number: string;
    bank_name: string | null;
    bank_branch: string | null;
    network: string | null;
    network_label: string | null;
    /** The bank's name for the account, which may not match the seller's. */
    resolved_name: string | null;
    resolved: boolean;
    resolved_at: string | null;
    is_default: boolean;
};

export type SellerDocument = {
    value: string;
    label: string;
    guidance: string;
    required: boolean;
    uploaded: boolean;
    media_id: number | null;
    file_name: string | null;
    size: number | null;
    uploaded_at: string | null;
};

export type SellerProfile = {
    id: number;
    slug: string;
    business_name: string;
    type: SellerTypeCode;
    type_label: string;
    description: string | null;
    verified: boolean;
    /** "Verified", or "Not yet verified" for everything else. */
    verification_label: string;
    sells_breaker_stock: boolean;
    location: {
        province: string | null;
        city: string | null;
        street: string | null;
        plot_number: string | null;
        single_line: string;
        latitude: number | null;
        longitude: number | null;
    };
    contact: SellerContact;
    opening_hours: Record<string, { open: string; close: string }> | null;
    bay_count: number | null;
    logo_url: string | null;
    policies?: SellerPolicy[];
    rating: { average: number | null; count: number };
    member_since: string | null;
};

export type SellerVerificationEvent = {
    id: number;
    summary: string;
    status?: VerificationStatusValue;
    from?: VerificationStatusValue | null;
    to?: VerificationStatusValue;
    note?: string | null;
    reason: string | null;
    checklist?: Record<string, boolean> | null;
    actor: string;
    created_at: string;
};

export type SellerVerificationState = {
    value: VerificationStatusValue;
    label: string;
    guidance: string;
    verified: boolean;
    public_label: string;
    in_queue: boolean;
};

/**
 * The commercial terms MonaFind sets. Shown in the portal, never editable
 * there: both decide how much of a buyer's money reaches the seller and when.
 */
export type SellerCommercialTerms = {
    payment_mode: 'escrow' | 'direct';
    payment_mode_label: string;
    payment_mode_description: string;
    carries_reserve: boolean;
    monetisation_policy_id: number | null;
    editable: false;
};

export type SellerRegistrationDraft = {
    current_step: RegistrationStepValue;
    furthest_step: RegistrationStepValue;
    answers: Record<string, unknown>;
    submitted: boolean;
    seller: {
        id: number;
        business_name: string;
        type: SellerTypeCode;
        type_label: string;
        registration_number: string | null;
        single_line: string;
        policies: SellerPolicy[];
        payout_accounts: PayoutAccount[];
        documents: SellerDocument[];
    } | null;
};

export type RegistrationCompleteness = Record<
    'type' | 'business' | 'policies' | 'payout' | 'documents',
    boolean
>;
