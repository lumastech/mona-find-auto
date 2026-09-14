import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import VehiclePicker from '@/components/storefront/VehiclePicker.vue';
import type { NavMake } from '@/types';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        get: (url: string) => visit(url),
    },
    usePage: () => ({
        props: {
            nav: {
                categories: [],
                makes,
                years: [2026, 2025, 2024, 2023],
            },
        },
    }),
}));

const makes: NavMake[] = [
    {
        id: 1,
        name: 'Toyota',
        slug: 'toyota',
        is_popular: true,
        models: [
            {
                id: 11,
                name: 'Hilux',
                slug: 'hilux',
                start_year: 2012,
                end_year: 2015,
            },
            {
                id: 12,
                name: 'Corolla',
                slug: 'corolla',
                start_year: null,
                end_year: null,
            },
        ],
    },
    {
        id: 2,
        name: 'Nissan',
        slug: 'nissan',
        is_popular: false,
        models: [],
    },
];

describe('VehiclePicker', () => {
    beforeEach(() => {
        visit.mockClear();
        window.localStorage.clear();
    });

    it('leaves the model select disabled until a make is chosen', async () => {
        const wrapper = mount(VehiclePicker);

        expect(
            wrapper.get('#vehicle-model').attributes('disabled'),
        ).toBeDefined();

        await wrapper.get('#vehicle-make').setValue('1');

        expect(
            wrapper.get('#vehicle-model').attributes('disabled'),
        ).toBeUndefined();
    });

    it('offers only the years a model was actually built', async () => {
        const wrapper = mount(VehiclePicker);

        await wrapper.get('#vehicle-make').setValue('1');
        await wrapper.get('#vehicle-model').setValue('11');

        const years = wrapper
            .get('#vehicle-year')
            .findAll('option')
            .map((option) => option.text());

        expect(years).toEqual(['Any year', '2015', '2014', '2013', '2012']);
    });

    it('falls back to the platform range for a model with no recorded run', async () => {
        const wrapper = mount(VehiclePicker);

        await wrapper.get('#vehicle-make').setValue('1');
        await wrapper.get('#vehicle-model').setValue('12');

        const years = wrapper
            .get('#vehicle-year')
            .findAll('option')
            .map((option) => option.text());

        expect(years).toEqual(['Any year', '2026', '2025', '2024', '2023']);
    });

    it('clears the model when the make changes beneath it', async () => {
        const wrapper = mount(VehiclePicker);

        await wrapper.get('#vehicle-make').setValue('1');
        await wrapper.get('#vehicle-model').setValue('11');
        await wrapper.get('#vehicle-make').setValue('2');

        /*
         * Vue binds the placeholder to `null`, which the DOM reports by its
         * label rather than an empty string — so the user-visible reading is
         * what this asserts.
         */
        const model = wrapper.get('#vehicle-model')
            .element as HTMLSelectElement;

        expect(model.selectedOptions[0]?.textContent?.trim()).toBe('Any model');
        expect(model.querySelectorAll('option')).toHaveLength(1);
    });

    it('sends the chosen vehicle to the search page as filters', async () => {
        const wrapper = mount(VehiclePicker);

        await wrapper.get('#vehicle-make').setValue('1');
        await wrapper.get('#vehicle-model').setValue('11');
        await wrapper.get('#vehicle-year').setValue('2014');
        await wrapper.get('#vehicle-part').setValue('brake pads');
        await wrapper.get('form').trigger('submit');

        expect(visit).toHaveBeenCalledTimes(1);

        const url = visit.mock.calls[0]?.[0] as string;

        expect(url).toContain('make_id=1');
        expect(url).toContain('vehicle_model_id=11');
        expect(url).toContain('year=2014');
        expect(url).toContain('brake');
    });

    it('remembers the vehicle on the device for the next visit', async () => {
        const wrapper = mount(VehiclePicker);

        await wrapper.get('#vehicle-make').setValue('1');
        await wrapper.get('#vehicle-model').setValue('11');
        await wrapper.get('form').trigger('submit');

        expect(
            JSON.parse(window.localStorage.getItem('mfa.vehicle') ?? '{}'),
        ).toMatchObject({
            make_id: 1,
            make_name: 'Toyota',
            vehicle_model_id: 11,
            vehicle_model_name: 'Hilux',
        });
    });

    it('will not submit without a make', () => {
        const wrapper = mount(VehiclePicker);

        expect(
            wrapper.get('button[type="submit"]').attributes('disabled'),
        ).toBeDefined();
    });
});
