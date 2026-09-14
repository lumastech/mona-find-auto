import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RatingSummary from '@/components/ratings/RatingSummary.vue';
import StarRating from '@/components/ratings/StarRating.vue';
import type { RatingSummary as Summary } from '@/types';

/*
 * The bars matter more than the average. Four stars from forty people and
 * four stars from two ones and two fives are the same number and completely
 * different shops, and a buyer about to send money needs to be able to tell
 * them apart — so these tests are mostly about the breakdown surviving.
 */

const polarised: Summary = {
    count: 4,
    average: 3,
    breakdown: { 1: 2, 2: 0, 3: 0, 4: 0, 5: 2 },
    percentages: { 1: 50, 2: 0, 3: 0, 4: 0, 5: 50 },
    verified_count: 4,
};

const empty: Summary = {
    count: 0,
    average: null,
    breakdown: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 },
    percentages: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 },
    verified_count: 0,
};

describe('RatingSummary', () => {
    it('draws a bar for every star, including the ones nobody chose', () => {
        const wrapper = mount(RatingSummary, {
            props: { summary: polarised },
        });

        expect(wrapper.findAll('li')).toHaveLength(5);
        expect(wrapper.text()).toContain('4 reviews');
    });

    it('describes each bar for a screen reader', () => {
        const wrapper = mount(RatingSummary, {
            props: { summary: polarised },
        });

        const labels = wrapper
            .findAll('[role="img"]')
            .map((bar) => bar.attributes('aria-label'));

        expect(labels).toContain('50% gave 1 stars');
        expect(labels).toContain('50% gave 5 stars');
    });

    it('says so plainly when nobody has reviewed the shop', () => {
        const wrapper = mount(RatingSummary, { props: { summary: empty } });

        expect(wrapper.text()).toContain('No reviews yet');
        expect(wrapper.findAll('li')).toHaveLength(0);
    });

    it('counts verified purchases separately from reviews', () => {
        const wrapper = mount(RatingSummary, {
            props: {
                summary: { ...polarised, verified_count: 3 },
            },
        });

        expect(wrapper.text()).toContain('3 verified');
    });

    it('shows the average through the same stars the form collected', () => {
        const wrapper = mount(RatingSummary, {
            props: { summary: polarised },
        });

        expect(wrapper.findComponent(StarRating).props('modelValue')).toBe(3);
    });
});
