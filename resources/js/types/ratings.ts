import type { BadgeVariantName } from './catalog';

/** The five directions a rating can run in. Three are public. */
export type RatingDirectionValue =
    | 'buyer_to_seller'
    | 'buyer_to_mechanic'
    | 'seller_to_buyer'
    | 'seller_to_mechanic'
    | 'mechanic_to_buyer';

export type RatingStatusValue = 'published' | 'pending_review' | 'hidden';

export type RatingPhoto = {
    id: number;
    url: string;
    thumb_url: string;
};

export type RatingReport = {
    id: number;
    reason: string;
    reason_label: string;
    details: string | null;
    status: string;
    status_label: string;
    status_variant: BadgeVariantName;
    reporter: string;
    created_at: string | null;
};

/**
 * One rating as the server chose to show it.
 *
 * The moderation fields are present only for staff — see RatingResource — so
 * everything below `can` is optional here rather than being faked with empty
 * values a template might render.
 */
export type Rating = {
    id: number;
    direction: RatingDirectionValue;
    is_public: boolean;
    stars: number;
    body: string | null;
    author: string;
    verified_purchase: boolean;
    created_at: string | null;
    photos: RatingPhoto[];
    reply: { body: string | null; at: string | null } | null;
    can: { reply: boolean; report: boolean };

    /* Staff only. */
    status?: RatingStatusValue;
    status_label?: string;
    status_variant?: BadgeVariantName;
    screen_flags?: { value: string; label: string }[];
    was_redacted?: boolean;
    reports_count?: number;
    reports?: RatingReport[];
    moderation_reason?: string | null;
    moderated_by?: string | null;
    moderated_at?: string | null;
    submitted_by?: string;
    source?: { type: string; label: string; id: number };
    subject?: string | null;

    /* Seller portal: the buyer a private rating is about. */
    buyer?: string | null;
};

/** The five bars under a star average. */
export type RatingSummary = {
    count: number;
    average: number | null;
    breakdown: Record<string, number>;
    percentages: Record<string, number>;
    verified_count: number;
};

/** "You can rate this, and you have not yet." */
export type RatingPrompt = {
    direction: RatingDirectionValue;
    direction_label: string;
    is_public: boolean;
    source_type: string;
    source_id: number;
    ratee_name: string;
    heading: string;
};

export type TrustBandValue = 'strong' | 'fair' | 'watch' | 'critical';

export type SellerTrustRow = {
    seller: {
        id: number;
        name: string;
        slug: string;
        verification_status: string;
        payment_mode: string;
    };
    ratings_count: number;
    average_stars: number | null;
    breakdown: RatingSummary;
    dispute_rate_percent: number;
    completed_orders: number;
    trust_score: number;
    trust_band: TrustBandValue;
    trust_band_label: string;
    trust_band_variant: BadgeVariantName;
    recommended_actions: string[];
    computed_at: string | null;
};
