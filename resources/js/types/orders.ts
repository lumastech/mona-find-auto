/**
 * Shapes owned by the Orders module: checkout, orders, fulfilment, disputes.
 *
 * Two things in here are worth reading as intent rather than as data.
 *
 * `OrderSummary.can` is answered by the server from the same rules the state
 * machine enforces. A page must branch on those flags rather than work its
 * own permissions out of a status string, because a page that reasons about
 * status will eventually offer a button the server refuses.
 *
 * Every policy in `CheckoutSellerGroup.policies` carries its id AND its
 * version, and both are posted back. Seller policies are published as new
 * versions rather than edited, so the pair is what lets the acceptance record
 * name exactly the text that was on screen — and lets the server refuse if
 * the seller republished while the buyer was reading.
 */

import type { CartLine } from './shopping';
import type { BadgeVariantName } from './catalog';

export type OrderStatusValue =
    | 'pending_payment'
    | 'paid'
    | 'seller_confirmed'
    | 'ready_for_pickup'
    | 'dispatched'
    | 'collected'
    | 'delivered'
    | 'completed'
    | 'closed'
    | 'cancelled'
    | 'disputed'
    | 'refunded';

export type FulfilmentMethodValue = 'pickup' | 'delivery';

export type PaymentMethodValue = 'card' | 'mobile_money';

export type DisputeStatusValue = 'open' | 'under_review' | 'resolved';

export type DisputeResolutionValue =
    | 'release'
    | 'partial_refund'
    | 'full_refund';

export type OrderActorTypeValue = 'buyer' | 'seller' | 'staff' | 'system';

/**
 * A choice offered at checkout, with the sentence that explains it.
 *
 * Sellers' LabelledOption is a bare value/label pair; a payment method has to
 * carry its own explanation ("Airtel Money, MTN MoMo or Zamtel Kwacha"), so
 * this widens it rather than redefining the shared name.
 */
export type DescribedOption = {
    value: string;
    label: string;
    description?: string;
};

export type FulfilmentOption = {
    value: FulfilmentMethodValue;
    label: string;
    description: string;
    needs_address: boolean;
};

/**
 * One version of one seller policy, in full.
 *
 * The body is the whole text, not an excerpt: the acceptance modal is
 * blocking and has to be readable without leaving checkout. A buyer who has
 * to open another tab to read a refund policy is a buyer who does not read
 * it.
 */
export type CheckoutPolicy = {
    policy_id: number;
    type: string;
    label: string;
    version: number;
    body: string;
    effective_from: string | null;
    shows_platform_minimum: boolean;
};

/** MonaFind's own half of what the buyer accepts. */
export type PlatformTerms = {
    version: string;
    body: string;
    minimum_refund_days: number;
    minimum_refund_statement: string;
};

export type CheckoutAddress = {
    id: number;
    label: string;
    recipient_name: string;
    recipient_phone: string;
    single_line: string;
    directions: string | null;
    latitude: number | null;
    longitude: number | null;
    is_default: boolean;
};

export type CheckoutSellerGroup = {
    seller: {
        id: number;
        slug: string;
        business_name: string;
        verified: boolean;
        address: string;
        phone: string;
        latitude: number | null;
        longitude: number | null;
        place_id: string | null;
        opening_hours: Record<string, { open: string; close: string }> | null;
        delivery_note: string | null;
    };
    lines: CartLine[];
    subtotal_ngwee: number;
    delivery_fee_ngwee: number;
    /** False when the quoted fee could still move once an address is chosen. */
    delivery_fee_is_final: boolean;
    available_methods: FulfilmentOption[];
    default_method: FulfilmentMethodValue;
    policies: CheckoutPolicy[];
    missing_policies: string[];
};

export type Checkout = {
    groups: CheckoutSellerGroup[];
    items_total_ngwee: number;
    is_empty: boolean;
    blocks_checkout: boolean;
    seller_count: number;
    addresses: CheckoutAddress[];
    default_address_id: number | null;
    payment_methods: DescribedOption[];
    platform_terms: PlatformTerms;
};

/** What the buyer chose for one shop, as the form posts it. */
export type CheckoutSelection = {
    seller_id: number;
    fulfilment_method: FulfilmentMethodValue;
    user_address_id: number | null;
    delivery_instructions: string | null;
    accepted: boolean;
    accepted_policies: { policy_id: number; version: number }[];
};

export type OrderItem = {
    id: number;
    product_id: number;
    variant_id: number;
    name: string;
    variant_name: string | null;
    sku: string | null;
    condition: string | null;
    condition_label: string | null;
    inspection_status: string | null;
    inspection_label: string | null;
    unit_price_ngwee: number;
    quantity: number;
    total_ngwee: number;
    /** True when this price came from an accepted quote rather than a shelf. */
    was_quoted: boolean;
};

export type OrderTimelineEntry = {
    status: OrderStatusValue;
    headline: string;
    actor: string;
    actor_type: OrderActorTypeValue;
    reason: string | null;
    at: string;
};

export type OrderDispute = {
    id: number;
    reason: string;
    reason_label: string;
    details: string;
    status: DisputeStatusValue;
    status_label: string;
    status_variant: BadgeVariantName;
    resolution: DisputeResolutionValue | null;
    resolution_label: string | null;
    refund_ngwee: number;
    opened_at: string | null;
    resolved_at: string | null;
    photos: string[];
};

export type OrderDeliveryAddress = {
    recipient_name: string;
    recipient_phone: string;
    street: string;
    plot_number: string | null;
    city: string;
    province: string;
    directions: string | null;
    latitude: number | null;
    longitude: number | null;
    formatted_address: string | null;
};

export type OrderSummary = {
    /** The internal key, for addressing the order as a thread subject. */
    id: number;
    number: string;
    status: OrderStatusValue;
    status_label: string;
    status_description: string;
    status_variant: BadgeVariantName;
    fulfilment_method: FulfilmentMethodValue;
    fulfilment_label: string;
    seller: {
        id: number;
        slug: string;
        business_name: string;
        verified: boolean;
        phone: string;
        address: string;
        latitude: number | null;
        longitude: number | null;
    };
    items?: OrderItem[];
    items_total_ngwee: number;
    delivery_fee_ngwee: number;
    total_ngwee: number;
    refunded_ngwee: number;
    delivery_address: OrderDeliveryAddress | null;
    delivery_instructions: string | null;
    placed_at: string | null;
    paid_at: string | null;
    auto_complete_at: string | null;
    timeline?: OrderTimelineEntry[];
    dispute?: OrderDispute | null;
    /** Decided by the server, from the rules the state machine enforces. */
    can: {
        confirm_receipt: boolean;
        open_dispute: boolean;
        download_receipt: boolean;
    };
};

/** A dispute as the moderator queue shows it, order and all. */
export type DisputeRow = {
    id: number;
    reason: string;
    reason_label: string;
    covered_by_platform_minimum: boolean;
    details: string;
    status: DisputeStatusValue;
    status_label: string;
    status_variant: BadgeVariantName;
    resolution: DisputeResolutionValue | null;
    resolution_label: string | null;
    resolution_note: string | null;
    refund_ngwee: number;
    resolved_by: string | null;
    resolved_at: string | null;
    opened_at: string | null;
    opened_by: string;
    photos: { id: number; url: string; name: string }[];
    order: {
        number: string;
        status: OrderStatusValue;
        status_label: string;
        total_ngwee: number;
        fulfilment_label: string;
        paid_at: string | null;
        buyer: string;
        seller: string;
        seller_slug: string;
    };
};

export type ResolutionOption = {
    value: DisputeResolutionValue;
    label: string;
    description: string;
    needs_amount: boolean;
};
