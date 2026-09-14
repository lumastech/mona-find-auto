import { describe, expect, it } from 'vitest';
import {
    defaultCurrency,
    formatMoney,
    ngweeOf,
    toDecimalString,
} from '@/lib/money';

describe('formatMoney', () => {
    it('renders an amount with the kwacha symbol and two decimals', () => {
        expect(formatMoney(129905)).toBe('K 1,299.05');
        expect(formatMoney(1075)).toBe('K 10.75');
        expect(formatMoney(0)).toBe('K 0.00');
        expect(formatMoney(5)).toBe('K 0.05');
    });

    it('keeps the sign in front of the symbol', () => {
        expect(formatMoney(-5)).toBe('-K 0.05');
        expect(formatMoney(-129905)).toBe('-K 1,299.05');
    });

    it('can leave the symbol off', () => {
        expect(
            formatMoney(129905, defaultCurrency, { withSymbol: false }),
        ).toBe('1,299.05');
    });
});

describe('toDecimalString', () => {
    it('renders a plain decimal with no grouping', () => {
        expect(toDecimalString(129905)).toBe('1299.05');
        expect(toDecimalString(1000)).toBe('10.00');
        expect(toDecimalString(-5)).toBe('-0.05');
    });
});

describe('ngweeOf', () => {
    it('reads both an integer and a serialised Money object', () => {
        expect(ngweeOf(1075)).toBe(1075);
        expect(
            ngweeOf({
                ngwee: 1075,
                currency: 'ZMW',
                formatted: 'K 10.75',
                decimal: '10.75',
            }),
        ).toBe(1075);
    });
});
