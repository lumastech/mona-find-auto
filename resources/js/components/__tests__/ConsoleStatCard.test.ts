import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ConsoleStatCard from '@/components/admin/ConsoleStatCard.vue';
import type { ConsoleStat } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: {} }),
}));

/*
 * The other half of the console. A stat tile has one job the queue card does
 * not: to say what a figure MEANS, which is always a comparison. The tests
 * below are mostly about the cases where there is nothing honest to compare
 * against, because that is where a dashboard starts inventing news.
 */
const stat = (overrides: Partial<ConsoleStat> = {}): ConsoleStat => ({
    key: 'finance.gmv',
    label: 'Gross merchandise value',
    value: 41_200_000,
    previous: 34_000_000,
    change: 2118,
    format: 'money',
    direction: 'higher_is_better',
    href: '/admin/finance/dashboard',
    hint: 'What buyers actually paid, off the ledger.',
    spark: [1, 4, 2, 9, 3],
    ...overrides,
});

const render = (overrides: Partial<ConsoleStat> = {}) =>
    mount(ConsoleStatCard, { props: { stat: stat(overrides) } });

describe('ConsoleStatCard', () => {
    it('renders the figure, its movement and where the detail lives', () => {
        const card = render();

        expect(card.text()).toContain('Gross merchandise value');
        expect(card.text()).toContain('K 412,000.00');
        expect(card.text()).toContain('+21.2%');
        expect(card.find('a').attributes('href')).toBe(
            '/admin/finance/dashboard',
        );
    });

    /*
     * Money is integer ngwee end to end. A tile that divided by 100 to "make
     * it kwacha" is the first place a rounding error gets into a finance
     * screen.
     */
    it('formats an amount without ever dividing it', () => {
        expect(
            render({ value: 1, previous: null, change: null }).text(),
        ).toContain('K 0.01');
    });

    it('counts a tally in plain numbers', () => {
        const card = render({ format: 'count', value: 1420, change: null });

        expect(card.text()).toContain('1,420');
        expect(card.text()).not.toContain('K ');
    });

    it('says nothing about a movement it cannot measure', () => {
        const card = render({ previous: null, change: null });

        expect(card.text()).not.toContain('%');
        expect(card.text()).toContain('Gross merchandise value');
    });

    it('colours a rise as good news where the module said higher is better', () => {
        const markup = render({ change: 2118 }).html();

        expect(markup).toContain('text-success');
        expect(markup).not.toContain('text-danger');
    });

    /*
     * The whole reason direction is a server-side decision: disputes up a
     * fifth and revenue up a fifth are both "+20%".
     */
    it('colours the same rise as bad news where lower is better', () => {
        const markup = render({
            direction: 'lower_is_better',
            change: 2118,
        }).html();

        expect(markup).toContain('text-danger');
        expect(markup).not.toContain('text-success');
    });

    it('leaves a figure nobody would act on grey', () => {
        const markup = render({ direction: 'neutral', change: 2118 }).html();

        expect(markup).toContain('text-muted-foreground');
        expect(markup).not.toContain('text-success');
        expect(markup).not.toContain('text-danger');
    });

    it('treats no change as flat whichever way is up', () => {
        const card = render({ change: 0 });

        /* Unsigned: "+0.0%" claims a rise that did not happen. */
        expect(card.text()).toContain('0.0%');
        expect(card.text()).toContain('unchanged from the previous period');
        expect(card.html()).not.toContain('text-success');
    });

    /*
     * An arrow glyph reads as nothing at all, so the direction is spelled out
     * for anybody who is not looking at it.
     */
    it('spells the direction out for a screen reader', () => {
        expect(render({ change: -500 }).text()).toContain(
            'down on the previous period',
        );
        expect(render({ change: 2118 }).text()).toContain(
            'up on the previous period',
        );
    });

    it('draws a sparkline scaled to its own peak', () => {
        const card = render({ spark: [0, 9] });
        const points = card.find('polyline').attributes('points');

        /* Two points across a 120x28 box: the 0 on the floor, the peak at the top. */
        expect(points).toBe('0.00,28.00 120.00,0.00');
        expect(card.find('svg').attributes('aria-hidden')).toBe('true');
    });

    it('draws no sparkline where a module sent no series', () => {
        expect(render({ spark: null }).find('polyline').exists()).toBe(false);
    });

    it('is not a dead link when there is nowhere to go', () => {
        const card = render({ href: null });

        expect(card.find('a').exists()).toBe(false);
        expect(card.text()).toContain('Gross merchandise value');
    });

    /*
     * The brand rule: gold means "MonaFind vouched for it" and a raw palette
     * colour beside the beige reads as a rendering fault.
     */
    it('spends no raw palette colour and no gold on a trend', () => {
        const markup = render().html();

        expect(markup).not.toMatch(
            /\b(?:text|bg|border)-(?:amber|red|orange|yellow|green|gray|slate)-\d/,
        );
        expect(markup).not.toContain('trust');
    });
});
