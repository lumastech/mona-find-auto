/**
 * Shapes owned by the Shopping module: wishlists, carts and quotations.
 *
 * The thread running through all three is that time passes between choosing
 * something and paying for it. A wishlist row carries what changed since it
 * was saved; a cart line carries the price the buyer last saw as well as the
 * one they will pay; a quote carries the day it stops being an offer. None of
 * those second values is decoration — each is the difference between telling
 * a buyer what happened and quietly charging them for it.
 */

import type {
    BadgeVariantName,
    ConditionBadge,
    InspectionBadge,
    ProductCard,
} from './catalog';
import type { StockLevelBadge } from './inventory';

export type QuotationStatusValue =
    | 'open'
    | 'quoted'
    | 'accepted'
    | 'declined'
    | 'expired';

export type CartLineIssueValue =
    | 'price_changed'
    | 'quantity_reduced'
    | 'out_of_stock'
    | 'unavailable'
    | 'quote_expired';

/**
 * What has happened to a saved listing since it was saved.
 *
 * `difference_ngwee` is negative when the price fell. `noteworthy` is the
 * one flag a card should branch on — it is true when any of the others is,
 * so a row cannot end up rendering a "nothing changed" banner.
 */
export type WishlistChange = {
    saved_price_ngwee: number | null;
    current_price_ngwee: number | null;
    difference_ngwee: number | null;
    price_dropped: boolean;
    price_rose: boolean;
    went_out_of_stock: boolean;
    came_back_in_stock: boolean;
    in_stock: boolean;
    noteworthy: boolean;
};

export type WishlistItem = {
    id: number;
    saved_at: string | null;
    listing: ProductCard;
    change: WishlistChange;
    /** Whether "Move to cart" can do anything today. */
    purchasable: boolean;
};

/** Something the cart found wrong with a line when it re-checked it. */
export type CartLineIssue = {
    value: CartLineIssueValue;
    label: string;
    description: string;
    variant: BadgeVariantName;
    blocks_checkout: boolean;
};

export type CartLine = {
    id: number;
    quantity: number;
    unit_price_ngwee: number;
    /** What the buyer last saw, when that is not what they will pay. */
    previous_unit_price_ngwee: number | null;
    /** What they asked for, when the shelf could not fill it. */
    requested_quantity: number | null;
    line_total_ngwee: number;
    product: {
        id: number;
        name: string;
        slug: string;
        thumbnail_url: string | null;
        condition: ConditionBadge;
        inspection: InspectionBadge;
    };
    variant: {
        id: number;
        name: string | null;
        sku: string;
        level: StockLevelBadge;
        /** The ceiling for the stepper — the one place a count is shown. */
        max_quantity: number;
    };
    quotation: {
        id: number;
        valid_until: string | null;
        expired: boolean;
    } | null;
    issues: CartLineIssue[];
};

/**
 * One shop's part of the cart.
 *
 * There is no flat list of lines anywhere in this module's payloads. A
 * MonaFind cart is one purchase per shop, each dispatched separately under
 * that shop's own terms.
 */
export type CartSellerGroup = {
    seller: {
        id: number;
        slug: string;
        business_name: string;
        verified: boolean;
        city: string | null;
    };
    lines: CartLine[];
    subtotal_ngwee: number;
    unit_count: number;
    has_issues: boolean;
    blocks_checkout: boolean;
};

/** A line that was dropped when the cart re-checked itself. */
export type RemovedCartLine = {
    product_id: number;
    name: string;
    slug: string;
    seller: string;
    reason: CartLineIssueValue;
    reason_label: string;
};

export type Cart = {
    groups: CartSellerGroup[];
    total_ngwee: number;
    unit_count: number;
    line_count: number;
    seller_count: number;
    is_empty: boolean;
    has_changes: boolean;
    blocks_checkout: boolean;
    issues: CartLineIssue[];
    removed: RemovedCartLine[];
};

export type QuotationStatusBadge = {
    value: QuotationStatusValue;
    label: string;
    description: string;
    variant: BadgeVariantName;
    is_open: boolean;
};

/**
 * A quote request and its answer, for both sides of it.
 *
 * `is_acceptable` is computed on the server from the clock rather than read
 * off the status: a quote can be `quoted` in the database and already dead,
 * and a page that offers an Accept button on one is a page about to be
 * refused.
 */
export type Quotation = {
    id: number;
    status: QuotationStatusBadge;
    quantity: number;
    message: string | null;
    quoted_unit_price_ngwee: number | null;
    total_ngwee: number | null;
    valid_until: string | null;
    delivery_note: string | null;
    decline_reason: string | null;
    has_expired: boolean;
    is_acceptable: boolean;
    requested_at: string | null;
    quoted_at: string | null;
    accepted_at: string | null;
    listing: {
        id: number;
        name: string;
        slug: string;
        thumbnail_url: string | null;
        current_price_ngwee: number;
        variant_id: number;
        variant_name: string | null;
    };
    seller?: {
        id: number;
        slug: string;
        business_name: string;
        verified: boolean;
    };
    buyer?: { id: number; name: string };
};

/**
 * What every storefront page carries about this buyer's shopping.
 *
 * `saved_product_ids` is here so the heart on a product card can be filled
 * without Catalog, Search or Sellers passing a wishlist prop of their own.
 * It is capped server-side; past the cap the oldest saves render as empty
 * hearts until the buyer opens the wishlist itself.
 */
export type ShoppingCounts = {
    cart_count: number;
    wishlist_count: number;
    saved_product_ids: number[];
};

/** A listing held in the compare drawer. Client-side only. */
export type CompareEntry = {
    id: number;
    slug: string;
    name: string;
    thumbnail_url: string | null;
    price_ngwee: number | null;
    condition_label: string;
    inspection_label: string;
    stock_label: string;
    freshness_label: string | null;
    fitment: string | null;
    seller_name: string | null;
    delivery_available: boolean;
};
