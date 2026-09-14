/**
 * Shapes owned by the Inventory module: what is on a shelf, and how long
 * since anyone said so.
 *
 * Two independent things travel together on every listing, and neither
 * substitutes for the other. `stock` is availability — In stock, Low stock,
 * Out of stock. `freshness` is confidence — how long since the seller last
 * confirmed the shelf is real. "In stock" from a shop that has confirmed
 * nothing in a week is a weaker claim than "Low stock" from one that
 * confirmed this morning.
 */

import type { BadgeVariantName } from './catalog';

export type StockLevelValue = 'in_stock' | 'low_stock' | 'out_of_stock';

export type FreshnessStateValue = 'fresh' | 'ageing' | 'unconfirmed' | 'hidden';

export type StockImportStatusValue =
    | 'awaiting_confirmation'
    | 'applying'
    | 'applied'
    | 'failed'
    | 'discarded';

/** Availability, as a buyer is shown it. Never a bare quantity. */
export type StockLevelBadge = {
    value: StockLevelValue;
    label: string;
    variant: BadgeVariantName;
    available: boolean;
};

/**
 * Freshness as the storefront sees it.
 *
 * `label` is null for fresh and ageing stock: a badge on every listing saying
 * "normal" is noise, and it would make the warning on the others invisible.
 */
export type StorefrontFreshness = {
    value: FreshnessStateValue;
    label: string | null;
    description: string;
    variant: BadgeVariantName;
    confirmed_days_ago: number;
};

/** Freshness as the seller portal sees it: the same state, plus the clock. */
export type SellerFreshness = {
    value: FreshnessStateValue;
    label: string;
    description: string;
    variant: BadgeVariantName;
    needs_confirmation: boolean;
    hidden: boolean;
    confirmed_at: string | null;
    days_since_confirmed: number;
};

export type FreshnessStateOption = {
    value: FreshnessStateValue;
    label: string;
    description: string;
    variant: BadgeVariantName;
};

/** One option's stock position on the seller's stock screen. */
export type StockVariant = {
    id: number;
    sku: string;
    name: string | null;
    /** Integer ngwee, VAT-inclusive. Nothing divides it before <Money /> does. */
    price_ngwee: number;
    quantity: number;
    /** Null means "follow the platform default", which is a real choice. */
    low_stock_threshold: number | null;
    effective_low_stock_threshold: number;
    level: StockLevelBadge;
    is_default: boolean;
};

export type StockListing = {
    id: number;
    slug: string;
    name: string;
    thumbnail_url: string | null;
    status: { value: string; label: string; live: boolean };
    freshness: SellerFreshness;
    variants: StockVariant[];
    total_quantity: number | null;
};

/** The numbers the dashboard banner and the stock page header are built from. */
export type StockSummary = {
    needs_confirmation: number;
    hidden: number;
    unconfirmed: number;
    ageing: number;
    fresh: number;
    low_stock: number;
    out_of_stock: number;
};

/** One rejected row of a bulk upload, with every complaint about it. */
export type StockImportProblem = {
    line: number;
    sku: string | null;
    quantity: number | null;
    price_ngwee: number | null;
    errors: string[];
    valid: boolean;
};

export type StockImportBatch = {
    id: number;
    original_filename: string;
    status: {
        value: StockImportStatusValue;
        label: string;
        variant: BadgeVariantName;
        applicable: boolean;
        finished: boolean;
    };
    total_rows: number;
    valid_rows: number;
    invalid_rows: number;
    applied_rows: number;
    failure_reason: string | null;
    problems: StockImportProblem[];
    applied_at: string | null;
    created_at: string | null;
};
