import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import TermsAcceptanceDialog from '@/components/orders/TermsAcceptanceDialog.vue';
import type { CheckoutSellerGroup, PlatformTerms } from '@/types';

/*
 * The blocking terms modal.
 *
 * Two client requirements meet here and both are easy to break silently. The
 * modal must render the CURRENT versions of the seller's policies in full —
 * not links, not excerpts — and the platform's minimum refund rule must sit
 * beside the refund policy rather than in a footnote, so a buyer cannot be
 * talked out of it by the seller's own wording.
 *
 * The third assertion is what makes the acceptance record evidence: accepting
 * emits the policy ids AND versions that were on screen, which the server
 * then checks against what is current.
 */

const platformTerms: PlatformTerms = {
    version: '4',
    body: 'MonaFindAuto is a marketplace.',
    minimum_refund_days: 3,
    minimum_refund_statement:
        'MonaFind refunds a wrong or damaged item reported within 3 days.',
};

const group = (
    policies: CheckoutSellerGroup['policies'],
): CheckoutSellerGroup => ({
    seller: {
        id: 7,
        slug: 'kabwata-motors',
        business_name: 'Kabwata Motors',
        verified: true,
        address: 'Plot 42, Kabwata, Lusaka',
        phone: '+260977123456',
        latitude: null,
        longitude: null,
        place_id: null,
        opening_hours: null,
        delivery_note: null,
    },
    lines: [],
    subtotal_ngwee: 120_000,
    delivery_fee_ngwee: 0,
    delivery_fee_is_final: true,
    available_methods: [],
    default_method: 'pickup',
    policies,
    missing_policies: [],
});

const refundPolicy: CheckoutSellerGroup['policies'][number] = {
    policy_id: 11,
    type: 'refund',
    label: 'Refund policy',
    version: 3,
    body: 'No refunds on electrical parts once fitted.',
    effective_from: '2026-01-01T00:00:00+02:00',
    shows_platform_minimum: true,
};

const warrantyPolicy: CheckoutSellerGroup['policies'][number] = {
    policy_id: 12,
    type: 'warranty',
    label: 'Warranty policy',
    version: 1,
    body: 'Thirty days on rebuilt units.',
    effective_from: '2026-01-01T00:00:00+02:00',
    shows_platform_minimum: false,
};

/*
 * The dialog portals its content to the body, so the assertions read the
 * document rather than the wrapper. Mounting into the body is what makes the
 * portal land somewhere the test can see.
 */
const mountDialog = (policies = [refundPolicy, warrantyPolicy]) =>
    mount(TermsAcceptanceDialog, {
        props: { open: true, group: group(policies), platformTerms },
        attachTo: document.body,
    });

/** The portal lands after a tick, so every read waits for it. */
const openDialog = async (policies?: CheckoutSellerGroup['policies']) => {
    const wrapper = policies ? mountDialog(policies) : mountDialog();

    await flushPromises();

    return wrapper;
};

const dialogText = (): string => document.body.textContent ?? '';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('TermsAcceptanceDialog', () => {
    it('renders each policy in full, with its version', async () => {
        await openDialog();
        const text = dialogText();

        expect(text).toContain('No refunds on electrical parts once fitted.');
        expect(text).toContain('Version 3');
        expect(text).toContain('Thirty days on rebuilt units.');
        expect(text).toContain('Version 1');
    });

    it("shows MonaFind's minimum refund rule beside the refund policy only", async () => {
        await openDialog();
        const withRefund = dialogText();

        document.body.innerHTML = '';

        await openDialog([warrantyPolicy]);
        const withoutRefund = dialogText();

        expect(withRefund).toContain(platformTerms.minimum_refund_statement);
        expect(withoutRefund).not.toContain(
            platformTerms.minimum_refund_statement,
        );
    });

    it('always renders the platform terms, even for a seller with none of their own', async () => {
        await openDialog([]);
        const text = dialogText();

        expect(text).toContain('MonaFindAuto is a marketplace.');
        expect(text).toContain('has not published its own policies');
    });

    it('emits the ids and versions that were on screen', async () => {
        const wrapper = await openDialog();

        const accept = document.querySelector<HTMLButtonElement>(
            '[data-test="accept-terms"]',
        );

        expect(accept).not.toBeNull();
        accept?.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('accept')?.[0]?.[0]).toEqual([
            { policy_id: 11, version: 3 },
            { policy_id: 12, version: 1 },
        ]);

        /* Accepting closes the modal so checkout can carry on. */
        expect(wrapper.emitted('update:open')?.[0]?.[0]).toBe(false);
    });
});
