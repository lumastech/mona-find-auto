import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ConsoleQueueCard from '@/components/admin/ConsoleQueueCard.vue';
import type { ConsoleCounter } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
}));

/*
 * The tile staff triage the console by. Tone is the owning module's judgement
 * about its own queue, so the only thing this component decides is how loudly
 * to draw it — and that decision has to survive somebody restyling the page.
 */
const counter = (overrides: Partial<ConsoleCounter> = {}): ConsoleCounter => ({
    key: 'orders.disputes',
    label: 'Open disputes',
    value: 3,
    href: '/admin/disputes',
    tone: 'critical',
    hint: 'Money is held on both sides until each is decided.',
    ...overrides,
});

const render = (overrides: Partial<ConsoleCounter> = {}) =>
    mount(ConsoleQueueCard, { props: { counter: counter(overrides) } });

describe('ConsoleQueueCard', () => {
    it('renders the queue, its count and where to clear it', () => {
        const card = render();

        expect(card.text()).toContain('Open disputes');
        expect(card.text()).toContain('3');
        expect(card.text()).toContain('Money is held on both sides');
        expect(card.find('a').attributes('href')).toBe('/admin/disputes');
    });

    it('names the count for a screen reader, which cannot see the tone', () => {
        expect(render().find('a').attributes('aria-label')).toBe(
            'Open disputes: 3',
        );
    });

    it('drops the hint line rather than leaving a gap where one was', () => {
        const card = render({ hint: null });

        expect(card.text()).toContain('Open disputes');
        expect(card.text()).not.toContain('Money is held');
    });

    it('draws a critical queue in the danger role', () => {
        const markup = render({ tone: 'critical' }).html();

        expect(markup).toContain('bg-danger');
        expect(markup).toContain('text-danger');
    });

    it('draws a warning queue in the warning role', () => {
        const markup = render({ tone: 'warning' }).html();

        expect(markup).toContain('bg-warning');
        expect(markup).toContain('text-warning');
    });

    it('leaves a neutral queue in the plain foreground', () => {
        const markup = render({ tone: 'neutral' }).html();

        expect(markup).toContain('text-foreground');
        expect(markup).not.toContain('text-danger');
        expect(markup).not.toContain('text-warning');
    });

    /*
     * The brand rule, not a preference: amber beside Soft Gold reads as a
     * second trust mark rather than a caution, so a warning is burnt orange
     * from the token and never a Tailwind palette colour.
     */
    it.each(['critical', 'warning', 'neutral'] as const)(
        'spends no raw palette colour on a %s queue',
        (tone) => {
            expect(render({ tone }).html()).not.toMatch(
                /\b(?:text|bg|border)-(?:amber|red|orange|yellow|gray|slate)-\d/,
            );
        },
    );

    /*
     * The lift is decoration; a card that only moves for people who asked for
     * motion still reads exactly the same standing still.
     */
    it('lifts only where motion is welcome', () => {
        const markup = render().html();

        expect(markup).toContain('motion-safe:group-hover:-translate-y-1');
        expect(markup).not.toContain('group-hover:scale');
    });
});
