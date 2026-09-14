import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ConditionBadge from '@/components/catalog/ConditionBadge.vue';
import InspectionBadge from '@/components/catalog/InspectionBadge.vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import type {
    ConditionBadge as Condition,
    InspectionBadge as Inspection,
} from '@/types';

/*
 * The client requirement is that every product card and listing page shows
 * both badge families, and that they stay independent: the condition says
 * what the part is, the inspection badge says whether MonaFind looked at it.
 */

const brandNew: Condition = {
    value: 'brand_new',
    label: 'Brand New',
    description: 'Unused and unfitted, in its original packaging.',
    variant: 'default',
};

const carBreaker: Condition = {
    value: 'car_breaker',
    label: 'Car Breaker',
    description: 'Removed from a scrapped vehicle by a car breaker.',
    variant: 'outline',
    locked: true,
};

const uninspected: Inspection = {
    value: 'uninspected',
    label: 'Uninspected',
    description: 'MonaFind has not inspected this item.',
    variant: 'outline',
    inspected: false,
};

const inspected: Inspection = {
    value: 'inspected',
    label: 'Inspected',
    description: 'MonaFind staff have inspected this item.',
    variant: 'default',
    inspected: true,
};

describe('ConditionBadge', () => {
    it.each([
        [brandNew, 'Brand New'],
        [carBreaker, 'Car Breaker'],
        [
            {
                value: 'used',
                label: 'Used',
                description: 'Previously fitted.',
                variant: 'secondary',
            } as Condition,
            'Used',
        ],
    ])('renders the %s label from props', (condition, label) => {
        const wrapper = mount(ConditionBadge, { props: { condition } });

        expect(wrapper.text()).toContain(label);
        expect(wrapper.attributes('data-condition')).toBe(condition.value);
    });
});

describe('InspectionBadge', () => {
    it('labels an uninspected listing rather than leaving it blank', () => {
        const wrapper = mount(InspectionBadge, {
            props: { inspection: uninspected },
        });

        expect(wrapper.text()).toContain('Uninspected');
        expect(wrapper.attributes('data-inspection')).toBe('uninspected');
    });

    it('labels an inspected listing', () => {
        const wrapper = mount(InspectionBadge, {
            props: { inspection: inspected },
        });

        expect(wrapper.text()).toContain('Inspected');
        expect(wrapper.attributes('data-inspection')).toBe('inspected');
    });

    it('carries the explanation for assistive technology', () => {
        const wrapper = mount(InspectionBadge, {
            props: { inspection: uninspected },
        });

        expect(wrapper.find('.sr-only').text()).toContain(
            'MonaFind has not inspected this item',
        );
    });
});

describe('ListingBadges', () => {
    it('renders both badge families from props', () => {
        const wrapper = mount(ListingBadges, {
            props: { condition: brandNew, inspection: uninspected },
        });

        expect(wrapper.find('[data-condition]').exists()).toBe(true);
        expect(wrapper.find('[data-inspection]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Brand New');
        expect(wrapper.text()).toContain('Uninspected');
    });

    it('shows a brand new part that MonaFind has not inspected', () => {
        const wrapper = mount(ListingBadges, {
            props: { condition: brandNew, inspection: uninspected },
        });

        expect(
            wrapper.find('[data-condition]').attributes('data-condition'),
        ).toBe('brand_new');
        expect(
            wrapper.find('[data-inspection]').attributes('data-inspection'),
        ).toBe('uninspected');
    });

    it('shows a car breaker part that MonaFind has inspected', () => {
        /* The two attributes are independent — neither implies the other. */
        const wrapper = mount(ListingBadges, {
            props: { condition: carBreaker, inspection: inspected },
        });

        expect(wrapper.text()).toContain('Car Breaker');
        expect(wrapper.text()).toContain('Inspected');
    });

    it('never renders one badge without the other', () => {
        const combinations: Array<[Condition, Inspection]> = [
            [brandNew, uninspected],
            [brandNew, inspected],
            [carBreaker, uninspected],
            [carBreaker, inspected],
        ];

        combinations.forEach(([condition, inspection]) => {
            const wrapper = mount(ListingBadges, {
                props: { condition, inspection },
            });

            expect(wrapper.findAll('[data-condition]')).toHaveLength(1);
            expect(wrapper.findAll('[data-inspection]')).toHaveLength(1);
        });
    });

    it('tightens the spacing in a dense grid', () => {
        const compact = mount(ListingBadges, {
            props: {
                condition: brandNew,
                inspection: inspected,
                compact: true,
            },
        });
        const roomy = mount(ListingBadges, {
            props: { condition: brandNew, inspection: inspected },
        });

        expect(compact.classes()).toContain('gap-1');
        expect(roomy.classes()).toContain('gap-2');
    });
});
