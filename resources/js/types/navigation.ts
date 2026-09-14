import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

/**
 * The storefront header's two ways in, shared on every storefront page by
 * CatalogServiceProvider. Null inside the seller portal and staff console.
 */
export type NavCategory = {
    id: number;
    name: string;
    slug: string;
    children: NavCategoryChild[];
};

export type NavCategoryChild = {
    id: number;
    name: string;
    slug: string;
};

export type NavVehicleModel = {
    id: number;
    name: string;
    slug: string;
    /** Null where staff have not recorded a production run. */
    start_year: number | null;
    end_year: number | null;
};

export type NavMake = {
    id: number;
    name: string;
    slug: string;
    is_popular: boolean;
    models: NavVehicleModel[];
};

export type StorefrontNav = {
    categories: NavCategory[];
    makes: NavMake[];
    /** Newest first — the fallback range when a model declares none. */
    years: number[];
};

/** A buyer's remembered vehicle, held in localStorage on their device. */
export type SavedVehicle = {
    make_id: number;
    make_name: string;
    vehicle_model_id: number | null;
    vehicle_model_name: string | null;
    year: number | null;
};
