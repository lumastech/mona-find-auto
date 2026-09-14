import { describe, expect, it } from 'vitest';
import {
    formatChange,
    formatStatValue,
    sentimentOf,
    sparklinePoints,
} from '@/lib/console';

/*
 * The rules the console's tiles and charts share. They live here rather than
 * inside a component because both read them, and a tile disagreeing with the
 * axis beside it about the same amount is the kind of thing nobody reports
 * and everybody quietly stops trusting.
 */
describe('formatStatValue', () => {
    it('renders money from integer ngwee without dividing it', () => {
        expect(formatStatValue(41_200_000, 'money')).toBe('K 412,000.00');
        expect(formatStatValue(1, 'money')).toBe('K 0.01');
    });

    it('renders a percentage from hundredths of a percent', () => {
        expect(formatStatValue(1250, 'percent')).toBe('12.50%');
    });

    it('groups a tally and never offers half of one', () => {
        expect(formatStatValue(1420, 'count')).toBe('1,420');
        expect(formatStatValue(2.5, 'count')).toBe('3');
    });
});

describe('formatChange', () => {
    it('signs a movement and keeps one decimal', () => {
        expect(formatChange(2118)).toBe('+21.2%');
        expect(formatChange(-4000)).toBe('−40.0%');
        expect(formatChange(0)).toBe('0.0%');
    });

    /*
     * Past a thousand percent the figure stops being informative and starts
     * being a joke about a small denominator.
     */
    it('caps a runaway rather than printing it', () => {
        expect(formatChange(250_000)).toBe('+1,000%+');
    });
});

describe('sentimentOf', () => {
    it('reads a movement against the direction its module declared', () => {
        expect(sentimentOf(2118, 'higher_is_better')).toBe('good');
        expect(sentimentOf(2118, 'lower_is_better')).toBe('bad');
        expect(sentimentOf(-2118, 'lower_is_better')).toBe('good');
        expect(sentimentOf(-2118, 'higher_is_better')).toBe('bad');
    });

    it('calls no movement flat, whichever way is up', () => {
        expect(sentimentOf(0, 'higher_is_better')).toBe('flat');
        expect(sentimentOf(2118, 'neutral')).toBe('flat');
    });
});

describe('sparklinePoints', () => {
    it('scales to the series own peak, so a flat fortnight looks flat', () => {
        expect(sparklinePoints([0, 5, 10], 100, 20)).toBe(
            '0.00,20.00 50.00,10.00 100.00,0.00',
        );
    });

    /*
     * A series that never moves is drawn down the middle. On the floor it
     * would read as a run of zeroes, which is a different fact.
     */
    it('draws an unmoving series through the middle, not along the bottom', () => {
        expect(sparklinePoints([0, 0, 0], 100, 20)).toBe(
            '0.00,10.00 50.00,10.00 100.00,10.00',
        );
    });

    it('has nothing to draw for an empty series', () => {
        expect(sparklinePoints([], 100, 20)).toBe('');
    });
});
