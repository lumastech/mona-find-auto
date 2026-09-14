import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StarRating from '@/components/ratings/StarRating.vue';

/*
 * One component does both jobs — the readout on a review and the control on
 * the form — because two implementations drift, and a buyer who is shown
 * "4.5 stars" beside the words "you gave 4" has been told two things.
 */

describe('StarRating', () => {
    it('reads out a fractional average without rounding it away', () => {
        const wrapper = mount(StarRating, { props: { modelValue: 4.4 } });

        expect(wrapper.attributes('aria-label')).toBe('4.4 out of 5 stars');
    });

    it('says when nothing has been rated rather than showing zero stars', () => {
        const wrapper = mount(StarRating, { props: { modelValue: null } });

        expect(wrapper.attributes('aria-label')).toBe('Not yet rated');
    });

    it('is a labelled group of five radios when it collects a rating', () => {
        const wrapper = mount(StarRating, {
            props: { interactive: true, label: 'Your rating of Kitwe Spares' },
        });

        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(5);
        expect(wrapper.find('legend').text()).toBe(
            'Your rating of Kitwe Spares',
        );
    });

    it('emits the star that was chosen', async () => {
        const wrapper = mount(StarRating, { props: { interactive: true } });

        await wrapper.findAll('input[type="radio"]')[3]!.setValue();

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([4]);
    });
});
