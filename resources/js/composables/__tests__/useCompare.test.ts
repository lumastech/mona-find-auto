import { beforeEach, describe, expect, it } from 'vitest';
import { MAX_COMPARED, specRows, useCompare } from '@/composables/useCompare';
import type { ProductCard } from '@/types';

/*
 * The compare drawer is the one part of Shopping with no server behind it —
 * it holds up to four listings in sessionStorage and works out which rows
 * actually differ. Both of those rules are the client requirement: four is
 * what fits on a phone, and a table where every row reads the same is four
 * columns of noise rather than a comparison.
 */

const listing = (
    id: number,
    overrides: Partial<ProductCard> = {},
): ProductCard => ({
    id,
    slug: `listing-${id}`,
    name: `Listing ${id}`,
    condition: {
        value: 'used',
        label: 'Used',
        description: 'A second-hand part.',
        variant: 'secondary',
    },
    inspection: {
        value: 'uninspected',
        label: 'Uninspected',
        description: 'MonaFind has not inspected this item.',
        variant: 'outline',
        inspected: false,
    },
    price_ngwee: 45000,
    has_multiple_variants: false,
    in_stock: true,
    stock: {
        value: 'in_stock',
        label: 'In stock',
        variant: 'secondary',
        available: true,
    },
    freshness: {
        value: 'fresh',
        label: null,
        description: 'Confirmed recently.',
        variant: 'secondary',
        confirmed_days_ago: 1,
    },
    fitment: 'Toyota Hilux 2005-2015',
    delivery_available: true,
    thumbnail_url: null,
    seller: {
        id: 1,
        slug: 'shop',
        business_name: 'Kabwata Spares',
        verified: true,
        city: 'Lusaka',
    },
    ...overrides,
});

describe('useCompare', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
        useCompare().clear();
    });

    it('adds a listing and reports it as compared', () => {
        const { add, has, count } = useCompare();

        expect(add(listing(1))).toBe(true);
        expect(has(1)).toBe(true);
        expect(count.value).toBe(1);
    });

    it('does not add the same listing twice', () => {
        const { add, count } = useCompare();

        add(listing(1));
        add(listing(1));

        expect(count.value).toBe(1);
    });

    it('stops at four, which is what fits on a phone', () => {
        const { add, count, isFull } = useCompare();

        for (let id = 1; id <= MAX_COMPARED; id++) {
            expect(add(listing(id))).toBe(true);
        }

        expect(isFull.value).toBe(true);
        /* The fifth is refused rather than silently pushing one out. */
        expect(add(listing(99))).toBe(false);
        expect(count.value).toBe(MAX_COMPARED);
    });

    it('toggles a listing out again', () => {
        const { toggle, has } = useCompare();

        toggle(listing(1));
        expect(toggle(listing(1))).toBe(false);
        expect(has(1)).toBe(false);
    });

    it('closes the drawer when the last listing leaves', () => {
        const { add, remove, drawerOpen } = useCompare();

        add(listing(1));
        drawerOpen.value = true;
        remove(1);

        expect(drawerOpen.value).toBe(false);
    });

    it('survives a page load through sessionStorage', () => {
        const { add } = useCompare();

        add(listing(7));

        const stored: unknown = JSON.parse(
            window.sessionStorage.getItem('mfa.compare') ?? '[]',
        );

        expect(stored).toHaveLength(1);
        expect((stored as Array<{ id: number }>)[0].id).toBe(7);
    });

    it('keeps only what the table renders, not the whole card', () => {
        const { add, entries } = useCompare();

        add(listing(1));

        expect(entries.value[0]).toEqual({
            id: 1,
            slug: 'listing-1',
            name: 'Listing 1',
            thumbnail_url: null,
            price_ngwee: 45000,
            condition_label: 'Used',
            inspection_label: 'Uninspected',
            stock_label: 'In stock',
            freshness_label: null,
            fitment: 'Toyota Hilux 2005-2015',
            seller_name: 'Kabwata Spares',
            delivery_available: true,
        });
    });

    it('shows only the rows where the listings actually differ', () => {
        const { add, differences } = useCompare();

        add(listing(1));
        add(listing(2, { delivery_available: false }));

        const labels = differences.value.map((row) => row.label);

        /* Delivery differs; everything else on these two is identical. */
        expect(labels).toEqual(['Delivery']);
    });

    it('shows every row while there is only one listing to look at', () => {
        const { add, differences } = useCompare();

        add(listing(1));

        expect(differences.value).toHaveLength(specRows([]).length);
    });

    it('leads with condition and inspection, together', () => {
        const rows = specRows([]).map((row) => row.label);

        /* The two badges are siblings here as they are everywhere else. */
        expect(rows.slice(0, 2)).toEqual(['Condition', 'Inspection']);
    });
});
