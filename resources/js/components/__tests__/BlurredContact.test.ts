import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import BlurredContact from '@/components/storefront/BlurredContact.vue';
import type { SellerContact } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
}));

/** What the server sends a guest: labels, masked values, and a prompt. */
const guestContact: SellerContact = {
    visible: false,
    prompt: 'Log in to view',
    fields: [
        { key: 'phone', label: 'Phone', value: '+260 ••• ••• •••' },
        { key: 'email', label: 'Email', value: '••••••@••••••.zm' },
        {
            key: 'contact_person',
            label: 'Contact person',
            value: '•••••• •••••',
        },
    ],
};

/** What the server sends a logged-in buyer. */
const buyerContact: SellerContact = {
    visible: true,
    prompt: '',
    fields: [
        { key: 'phone', label: 'Phone', value: '+260977123456' },
        { key: 'email', label: 'Email', value: 'sales@kabwata.co.zm' },
        {
            key: 'contact_person',
            label: 'Contact person',
            value: 'Chanda Mwale',
        },
    ],
};

describe('BlurredContact', () => {
    it('shows every field label to a guest', () => {
        const wrapper = mount(BlurredContact, {
            props: { contact: guestContact },
        });

        expect(wrapper.text()).toContain('Phone');
        expect(wrapper.text()).toContain('Email');
        expect(wrapper.text()).toContain('Contact person');
    });

    it('blurs the values and prompts a guest to log in', () => {
        const wrapper = mount(BlurredContact, {
            props: { contact: guestContact, businessName: 'Kabwata Motors' },
        });

        const values = wrapper.findAll('dd');

        expect(values).toHaveLength(3);
        values.forEach((value) => {
            expect(value.classes()).toContain('blur-[3px]');
            expect(value.attributes('aria-hidden')).toBe('true');
        });
        expect(wrapper.text()).toContain('Log in to view');
        expect(wrapper.text()).toContain('Kabwata Motors');
    });

    it('never renders a real contact value for a guest', () => {
        const wrapper = mount(BlurredContact, {
            props: { contact: guestContact },
        });

        expect(wrapper.html()).not.toContain('977123456');
        expect(wrapper.html()).not.toContain('kabwata.co.zm');
    });

    it('shows a logged-in buyer the real values without a prompt', () => {
        const wrapper = mount(BlurredContact, {
            props: { contact: buyerContact },
        });

        expect(wrapper.text()).toContain('+260977123456');
        expect(wrapper.text()).toContain('Chanda Mwale');
        expect(wrapper.text()).not.toContain('Log in to view');
        expect(wrapper.findAll('dd')[0].classes()).not.toContain('blur-[3px]');
    });

    it('makes a visible phone and email actionable', () => {
        const wrapper = mount(BlurredContact, {
            props: { contact: buyerContact },
        });

        const links = wrapper.findAll('a');

        expect(links[0].attributes('href')).toBe('tel:+260977123456');
        expect(links[1].attributes('href')).toBe('mailto:sales@kabwata.co.zm');
    });
});
