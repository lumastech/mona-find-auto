/**
 * Shapes owned by the Catalog module: reference data, listings, their two
 * badges, and the moderation workflow.
 */

export type ConditionValue = 'brand_new' | 'used' | 'car_breaker';

export type InspectionStatusValue = 'uninspected' | 'inspected';

export type ListingStatusValue =
    | 'draft'
    | 'pending_review'
    | 'published'
    | 'unpublished'
    | 'rejected'
    | 'archived';

export type PartSourcingValue = 'oem' | 'aftermarket';

/** The badge variants the shared Badge component understands. */
export type BadgeVariantName =
    | 'default'
    | 'secondary'
    | 'destructive'
    | 'outline';

/**
 * The condition badge: what the part is.
 *
 * Always rendered beside an InspectionBadge — see ListingBadges. The two are
 * independent attributes and neither stands in for the other.
 */
export type ConditionBadge = {
    value: ConditionValue;
    label: string;
    description?: string;
    variant: BadgeVariantName;
    /** True for a car breaker, whose stock is second-hand by definition. */
    locked?: boolean;
};

/**
 * The inspection badge: whether MonaFind staff have looked at the part.
 *
 * Rendered on every card and listing page even when the answer is no — a
 * buyer who sees no badge cannot tell whether the part was checked and passed
 * or never checked at all.
 */
export type InspectionBadge = {
    value: InspectionStatusValue;
    label: string;
    description: string;
    variant: BadgeVariantName;
    inspected: boolean;
    /** Staff-only, always. False everywhere in the seller portal. */
    editable?: boolean;
};

export type ListingStatusBadge = {
    value: ListingStatusValue;
    label: string;
    guidance: string;
    variant: BadgeVariantName;
    editable: boolean;
    live: boolean;
};

export type LabelledValue = { value: string; label: string };

export type ConditionOption = {
    value: ConditionValue;
    label: string;
    description: string;
};

export type ListingStatusOption = {
    value: ListingStatusValue;
    label: string;
    guidance: string;
    variant: BadgeVariantName;
};

export type MakeOption = {
    id: number;
    name: string;
    slug?: string;
    country?: string | null;
    is_popular?: boolean;
    position?: number;
    is_active?: boolean;
    vehicle_models_count?: number;
};

/**
 * A make as the reference-data console lists it, with the slug its own route
 * is keyed by. The listing form asks for less than this.
 */
export type ReferenceMake = MakeOption & { slug: string };

export type VehicleModelOption = {
    id: number;
    make_id: number;
    name: string;
    slug?: string;
    body_type?: string | null;
    production_start_year: number | null;
    production_end_year: number | null;
    is_popular?: boolean;
    is_active?: boolean;
};

export type CategoryNode = {
    id: number;
    name: string;
    slug: string;
    depth: number;
    is_active: boolean;
    /** False for a root: headings are browsed, listings hang off the levels below. */
    selectable: boolean;
    children: CategoryNode[];
};

export type CategoryRow = {
    id: number;
    parent_id: number | null;
    name: string;
    slug: string;
    description: string | null;
    depth: number;
    position: number;
    is_active: boolean;
    selectable: boolean;
};

export type BreadcrumbNode = { id: number; name: string; slug: string };

export type ProductVariant = {
    id: number;
    sku: string;
    name: string | null;
    /** Integer ngwee, VAT-inclusive. Nothing divides it before <Money /> does. */
    price_ngwee: number;
    quantity: number;
    in_stock: boolean;
    /** What a buyer is shown. The raw quantity above is for seller screens. */
    level: import('./inventory').StockLevelBadge;
    is_default: boolean;
};

/**
 * A listing as it appears in a grid.
 *
 * `condition` and `inspection` are siblings here because they are siblings on
 * screen: ProductCard renders both through ListingBadges and cannot render
 * one alone.
 */
export type ProductCard = {
    id: number;
    slug: string;
    name: string;
    condition: ConditionBadge;
    inspection: InspectionBadge;
    price_ngwee: number | null;
    has_multiple_variants: boolean;
    in_stock: boolean;
    /** Availability and confidence. Independent, and shown together. */
    stock: import('./inventory').StockLevelBadge;
    freshness: import('./inventory').StorefrontFreshness;
    fitment: string | null;
    delivery_available: boolean;
    thumbnail_url: string | null;
    seller?: {
        id: number;
        slug: string;
        business_name: string;
        verified: boolean;
        city: string | null;
    };
    category?: { id: number; name: string; slug: string };
};

export type SpecificationRow = { label: string; value: string };

export type ListingPhoto = {
    id: number;
    thumb: string;
    card: string;
    web: string;
    alt: string;
};

export type ListingVideo = { poster: string | null; source: string | null };

export type ProductDetail = ProductCard & {
    description: string;
    warranty_text: string | null;
    part_number: string | null;
    oem_number: string | null;
    sourcing: { value: PartSourcingValue; label: string };
    specification: SpecificationRow[];
    photos: ListingPhoto[];
    video: ListingVideo | null;
    variants: ProductVariant[];
    /** Variant ids this buyer has asked to be told about. Empty for a guest. */
    stock_alerts: number[];
    seller?: ProductCard['seller'] & {
        type_label: string;
        verification_label: string;
        location: string | null;
        contact: import('./sellers').SellerContact;
        policies: import('./sellers').SellerPolicy[];
        rating: { average: number | null; count: number };
    };
    published_at: string | null;
};

export type SellerListingPhoto = {
    id: number;
    thumb: string;
    card: string;
    name: string | null;
};

/**
 * A listing as its own seller sees it: the storefront shape plus where it is
 * in its lifecycle and what a moderator said about it.
 */
export type SellerListing = {
    id: number;
    slug: string;
    name: string;
    description: string;
    status: ListingStatusBadge;
    condition: ConditionBadge;
    inspection: InspectionBadge;
    category_id: number;
    category?: string;
    make_id: number | null;
    vehicle_model_id: number | null;
    year_from: number | null;
    year_to: number | null;
    sourcing: PartSourcingValue;
    part_number: string | null;
    oem_number: string | null;
    engine_size_cc: number | null;
    engine_code: string | null;
    fuel_type: string | null;
    transmission: string | null;
    drive_type: string | null;
    body_type: string | null;
    trim: string | null;
    chassis_compatibility: string | null;
    warranty_text: string | null;
    delivery_available: boolean;
    variants: ProductVariant[];
    photos: SellerListingPhoto[];
    video: { id: number; name: string; processed: boolean } | null;
    rejection_reason: string | null;
    /** Keyed by the form field the seller has to go and fix. */
    rejection_fields: Record<string, string>;
    submitted_at: string | null;
    published_at: string | null;
    updated_at: string | null;
};

export type ListingReviewEntry = {
    id: number;
    summary: string;
    reason: string | null;
    field_reasons: Record<string, string>;
    note?: string | null;
    created_at: string | null;
};

export type ListingLimits = {
    min_photos: number;
    max_photos: number;
    max_video_seconds: number;
};

export type DuplicateWarning = { part_number: string; count: number };
