import { computed, ref, type ComputedRef, type Ref } from 'vue';
import type { CompareEntry, ProductCard } from '@/types';

/**
 * The compare drawer: up to four listings, held in the tab.
 *
 * Entirely client-side and entirely in sessionStorage, which is the right
 * scope for what this is. Comparing three alternators is a decision somebody
 * makes in one sitting; carrying it across to next week — or, worse, to
 * another device — would mean opening MonaFind to a drawer full of parts
 * already bought. sessionStorage forgets when the tab does, which is exactly
 * when the comparison stops being interesting.
 *
 * The store is module-level rather than per-caller so that the drawer, the
 * cards and the storefront header all read the same list; a `ref` created
 * inside the composable would give each component its own.
 */

/** Four columns is what fits on a phone before the table stops being readable. */
export const MAX_COMPARED = 4;

const STORAGE_KEY = 'mfa.compare';

const entries = ref<CompareEntry[]>(readStored());
const drawerOpen = ref(false);

export type UseCompareReturn = {
    entries: Ref<CompareEntry[]>;
    drawerOpen: Ref<boolean>;
    count: ComputedRef<number>;
    isFull: ComputedRef<boolean>;
    /** The columns worth showing: rows where the listings actually differ. */
    differences: ComputedRef<Array<{ label: string; values: string[] }>>;
    has: (id: number) => boolean;
    toggle: (listing: ProductCard) => boolean;
    add: (listing: ProductCard) => boolean;
    remove: (id: number) => void;
    clear: () => void;
};

export function useCompare(): UseCompareReturn {
    const count = computed(() => entries.value.length);
    const isFull = computed(() => entries.value.length >= MAX_COMPARED);

    const has = (id: number): boolean =>
        entries.value.some((entry) => entry.id === id);

    /**
     * @returns whether the listing is now in the drawer.
     */
    const add = (listing: ProductCard): boolean => {
        if (has(listing.id)) {
            return true;
        }

        if (isFull.value) {
            return false;
        }

        entries.value = [...entries.value, toEntry(listing)];
        persist();

        return true;
    };

    const remove = (id: number): void => {
        entries.value = entries.value.filter((entry) => entry.id !== id);
        persist();

        if (entries.value.length === 0) {
            drawerOpen.value = false;
        }
    };

    const toggle = (listing: ProductCard): boolean => {
        if (has(listing.id)) {
            remove(listing.id);

            return false;
        }

        return add(listing);
    };

    const clear = (): void => {
        entries.value = [];
        drawerOpen.value = false;
        persist();
    };

    /**
     * The spec table, reduced to the rows that actually differ.
     *
     * A comparison of four brake discs where every row reads the same is
     * four columns of noise — the buyer is looking for the one line that is
     * not identical, so that is the only kind of line this returns.
     */
    const differences = computed(() => {
        if (entries.value.length < 2) {
            return specRows(entries.value);
        }

        return specRows(entries.value).filter(
            (row) => new Set(row.values).size > 1,
        );
    });

    return {
        entries,
        drawerOpen,
        count,
        isFull,
        differences,
        has,
        toggle,
        add,
        remove,
        clear,
    };
}

/**
 * The rows of the comparison table, in the order a buyer reads them.
 *
 * Price first because it is what the drawer is for; the two badges next,
 * together and never one without the other, because that pairing is the same
 * promise the cards make.
 */
export function specRows(
    items: CompareEntry[],
): Array<{ label: string; values: string[] }> {
    return [
        { label: 'Condition', values: items.map((i) => i.condition_label) },
        { label: 'Inspection', values: items.map((i) => i.inspection_label) },
        { label: 'Availability', values: items.map((i) => i.stock_label) },
        {
            label: 'Stock confirmed',
            values: items.map((i) => i.freshness_label ?? 'Recently'),
        },
        { label: 'Fits', values: items.map((i) => i.fitment ?? '—') },
        { label: 'Seller', values: items.map((i) => i.seller_name ?? '—') },
        {
            label: 'Delivery',
            values: items.map((i) => (i.delivery_available ? 'Yes' : 'No')),
        },
    ];
}

/**
 * Keep only what the table renders.
 *
 * A whole ProductCard in sessionStorage would carry media URLs and badge
 * descriptions that go stale the moment a seller edits the listing — and the
 * drawer would then be showing a price nobody is offering. What is stored is
 * a snapshot for a single sitting, and the buyer opens the listing to act on
 * it.
 */
function toEntry(listing: ProductCard): CompareEntry {
    return {
        id: listing.id,
        slug: listing.slug,
        name: listing.name,
        thumbnail_url: listing.thumbnail_url,
        price_ngwee: listing.price_ngwee,
        condition_label: listing.condition.label,
        inspection_label: listing.inspection.label,
        stock_label: listing.stock.label,
        freshness_label: listing.freshness.label,
        fitment: listing.fitment,
        seller_name: listing.seller?.business_name ?? null,
        delivery_available: listing.delivery_available,
    };
}

/**
 * Read the drawer back on a page load.
 *
 * Storage is absent during SSR and can throw in a locked-down browser, so
 * every path here ends in an empty drawer rather than an exception — an
 * empty comparison is a fine thing to show somebody; a broken storefront is
 * not.
 */
function readStored(): CompareEntry[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY);
        const parsed: unknown = raw === null ? [] : JSON.parse(raw);

        if (!Array.isArray(parsed)) {
            return [];
        }

        return (parsed as CompareEntry[])
            .filter((entry) => typeof entry?.id === 'number')
            .slice(0, MAX_COMPARED);
    } catch {
        return [];
    }
}

function persist(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(
            STORAGE_KEY,
            JSON.stringify(entries.value),
        );
    } catch {
        /* A full or disabled store is not a reason to break the page. */
    }
}
