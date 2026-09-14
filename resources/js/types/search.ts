/**
 * Shapes owned by the Search module.
 *
 * A search result is an ordinary listing card with one thing added: the tier
 * it earned against the words the buyer typed. That is what lets a page of
 * partial matches say so, instead of quietly presenting near-misses as though
 * they were what was asked for.
 */

import type { ProductCard } from './catalog';

export type MatchTierValue = 'exact' | 'partial' | 'related';

export type SearchSortValue =
    | 'recommended'
    | 'price_asc'
    | 'price_desc'
    | 'nearest'
    | 'newest';

export type MatchBadge = {
    tier: MatchTierValue;
    label: string;
    /** The "Why am I seeing this?" answer, in the buyer's terms. */
    explanation: string;
    variant: string;
};

export type SearchHit = ProductCard & { match: MatchBadge };

export type SortOption = {
    value: SearchSortValue;
    label: string;
    description: string;
    needs_location: boolean;
};

/** A facet value backed by a reference row: a make, a model, a town. */
export type FacetCount = { id: number; name: string; count: number };

/** A facet value backed by an enum: a condition, a seller type. */
export type FacetChoice = { value: string; label: string; count: number };

export type SearchFacets = {
    makes: FacetCount[];
    vehicle_models: FacetCount[];
    categories: FacetCount[];
    provinces: FacetCount[];
    cities: FacetCount[];
    conditions: FacetChoice[];
    sourcing: FacetChoice[];
    seller_types: FacetChoice[];
    /** Counts for the three yes/no filters; a checkbox needs no "no" option. */
    inspected: number;
    verified: number;
    delivery: number;
};

/**
 * Everything the buyer narrowed by, exactly as it goes back into the URL.
 * Absent keys are unset filters — never present-and-empty, so a chip list can
 * be built by walking it.
 */
export type SearchFilters = {
    category_id?: number;
    make_id?: number;
    vehicle_model_id?: number;
    year?: number;
    condition?: string;
    inspected?: boolean;
    sourcing?: string;
    min_price?: number;
    max_price?: number;
    seller_type?: string;
    verified?: boolean;
    delivery?: boolean;
    in_stock?: boolean;
    province_id?: number;
    city_id?: number;
    radius_km?: number;
};

export type SearchLocation = {
    lat: number;
    lng: number;
    radius_km: number | null;
};
