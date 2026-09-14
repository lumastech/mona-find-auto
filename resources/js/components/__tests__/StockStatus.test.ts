import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StockStatus from '@/components/inventory/StockStatus.vue';
import type { StockLevelBadge, StorefrontFreshness } from '@/types';

/*
 * The client requirement is that a buyer can read availability off every card
 * and listing page — In stock, Low stock, Out of stock — and that a listing
 * nobody has confirmed recently says so beside it. The two are independent:
 * "In stock" from a shop that has confirmed nothing in a week is a weaker
 * claim than "Low stock" from one that confirmed this morning.
 */

const inStock: StockLevelBadge = {
    value: 'in_stock',
    label: 'In stock',
    variant: 'secondary',
    available: true,
};

const lowStock: StockLevelBadge = {
    value: 'low_stock',
    label: 'Low stock',
    variant: 'outline',
    available: true,
};

const outOfStock: StockLevelBadge = {
    value: 'out_of_stock',
    label: 'Out of stock',
    variant: 'destructive',
    available: false,
};

const fresh: StorefrontFreshness = {
    value: 'fresh',
    label: null,
    description: 'The seller confirmed this stock in the last few days.',
    variant: 'secondary',
    confirmed_days_ago: 1,
};

const ageing: StorefrontFreshness = {
    value: 'ageing',
    label: null,
    description: 'Confirm your stock to keep this listing ranking well.',
    variant: 'outline',
    confirmed_days_ago: 4,
};

const unconfirmed: StorefrontFreshness = {
    value: 'unconfirmed',
    label: 'Stock unconfirmed',
    description:
        'The seller has not confirmed this stock recently. Check with them before you travel.',
    variant: 'destructive',
    confirmed_days_ago: 9,
};

describe('StockStatus', () => {
    it.each([
        [inStock, 'In stock'],
        [lowStock, 'Low stock'],
        [outOfStock, 'Out of stock'],
    ])('renders the %s level from props', (stock, label) => {
        const wrapper = mount(StockStatus, { props: { stock } });

        expect(wrapper.text()).toContain(label);
    });

    it('warns about stock nobody has confirmed', () => {
        const wrapper = mount(StockStatus, {
            props: { stock: inStock, freshness: unconfirmed },
        });

        expect(wrapper.text()).toContain('In stock');
        expect(wrapper.text()).toContain('Stock unconfirmed');
    });

    it('stays quiet about stock that is simply normal', () => {
        /* A badge on every listing saying "normal" hides the ones that matter. */
        [fresh, ageing].forEach((freshness) => {
            const wrapper = mount(StockStatus, {
                props: { stock: inStock, freshness },
            });

            expect(wrapper.text()).toContain('In stock');
            expect(wrapper.text()).not.toContain('unconfirmed');
        });
    });

    it('shows availability even when freshness is not supplied', () => {
        const wrapper = mount(StockStatus, { props: { stock: outOfStock } });

        expect(wrapper.text()).toContain('Out of stock');
    });

    it('carries the freshness explanation for a buyer who hovers it', () => {
        const wrapper = mount(StockStatus, {
            props: { stock: inStock, freshness: unconfirmed },
        });

        const badges = wrapper.findAll('[data-slot="badge"]');

        expect(badges[1].attributes('title')).toContain(
            'Check with them before you travel',
        );
    });

    it('tightens the spacing in a dense grid', () => {
        const compact = mount(StockStatus, {
            props: { stock: inStock, compact: true },
        });
        const roomy = mount(StockStatus, { props: { stock: inStock } });

        expect(compact.classes()).toContain('gap-1');
        expect(roomy.classes()).toContain('gap-2');
    });
});
