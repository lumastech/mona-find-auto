import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Money from '@/components/Money.vue';
import { defaultCurrency } from '@/lib/money';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            platform: { currency: defaultCurrency, timezone: 'Africa/Lusaka' },
        },
    }),
}));

describe('Money', () => {
    it('renders an amount held in ngwee', () => {
        const wrapper = mount(Money, { props: { amount: 129905 } });

        expect(wrapper.text()).toBe('K 1,299.05');
        expect(wrapper.attributes('data-ngwee')).toBe('129905');
    });

    it('accepts a serialised Money object', () => {
        const wrapper = mount(Money, {
            props: {
                amount: {
                    ngwee: 1075,
                    currency: 'ZMW',
                    formatted: 'K 10.75',
                    decimal: '10.75',
                },
            },
        });

        expect(wrapper.text()).toBe('K 10.75');
    });

    it('can drop the currency symbol', () => {
        const wrapper = mount(Money, {
            props: { amount: 1075, withSymbol: false },
        });

        expect(wrapper.text()).toBe('10.75');
    });

    it('marks a signed positive amount and prefixes it', () => {
        const wrapper = mount(Money, { props: { amount: 1075, signed: true } });

        expect(wrapper.text()).toBe('+K 10.75');
        expect(wrapper.classes()).toContain('text-emerald-600');
    });

    it('marks a signed negative amount as destructive', () => {
        const wrapper = mount(Money, {
            props: { amount: -1075, signed: true },
        });

        expect(wrapper.text()).toBe('-K 10.75');
        expect(wrapper.classes()).toContain('text-destructive');
    });

    it('exposes the exact amount for copying', () => {
        const wrapper = mount(Money, { props: { amount: 129905 } });

        expect(wrapper.attributes('title')).toBe('ZMW 1299.05');
    });
});
