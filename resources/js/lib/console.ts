/**
 * How the staff console renders the figures modules hand it.
 *
 * Shared between the stat tiles and the charts rather than written twice:
 * the tile and the axis beside it showing the same amount two different ways
 * is the kind of thing nobody reports and everybody stops trusting.
 *
 * Every value crosses the wire as an INTEGER in its own minor unit — ngwee
 * for money, hundredths of a percent for a rate, a plain tally for a count —
 * so nothing here divides before formatting.
 */

import { defaultCurrency, formatMoney, type CurrencyConfig } from '@/lib/money';
import type { ConsoleStatDirection, ConsoleStatFormat } from '@/types';

/**
 * A figure as a person reads it.
 *
 * A count is rounded because a fractional order does not exist and a chart
 * axis will otherwise offer a tick at 2.5.
 */
export function formatStatValue(
    value: number,
    format: ConsoleStatFormat,
    currency: CurrencyConfig = defaultCurrency,
): string {
    if (format === 'money') {
        return formatMoney(value, currency, { withSymbol: true });
    }

    if (format === 'percent') {
        return `${(Math.round(value) / 100).toFixed(2)}%`;
    }

    return Math.round(value).toLocaleString('en-ZM');
}

/**
 * A movement in hundredths of a percent as a signed percentage.
 *
 * One decimal place, because the second one is noise at this size and a tile
 * reading "+12.4%" is scanned in a glance where "+12.37%" is read.
 *
 * Past a thousand percent the number stops being informative and starts being
 * a joke about a small denominator, so it is capped with a "+" — the reader
 * who needs the real figure is one click from the screen that has it.
 */
export function formatChange(basisPoints: number): string {
    const sign = basisPoints > 0 ? '+' : basisPoints < 0 ? '−' : '';
    const magnitude = Math.abs(basisPoints);

    if (magnitude >= 100_000) {
        return `${sign}1,000%+`;
    }

    return `${sign}${(magnitude / 100).toFixed(1)}%`;
}

/** Whether a movement is good news, bad news, or neither. */
export type ChangeSentiment = 'good' | 'bad' | 'flat';

/**
 * Read a movement against the direction its owning module declared.
 *
 * A change of nothing is flat whichever way the module says is up: colouring
 * a 0.0% green because higher is better says something happened when nothing
 * did.
 */
export function sentimentOf(
    basisPoints: number,
    direction: ConsoleStatDirection,
): ChangeSentiment {
    if (basisPoints === 0 || direction === 'neutral') {
        return 'flat';
    }

    const rising = basisPoints > 0;

    return rising === (direction === 'higher_is_better') ? 'good' : 'bad';
}

/**
 * The points of a sparkline, as an SVG polyline over a unit-height box.
 *
 * Scaled to the series' own maximum rather than to zero-to-anything, because
 * the shape is the whole point of a sparkline: a fortnight wobbling between
 * 40 and 44 orders a day should look flat, and it does. A series that never
 * moves is drawn along the middle rather than flat on the floor, where it
 * would read as a run of zeroes.
 */
export function sparklinePoints(
    values: number[],
    width: number,
    height: number,
): string {
    if (values.length === 0) {
        return '';
    }

    const peak = Math.max(...values);
    const step = values.length > 1 ? width / (values.length - 1) : 0;

    return values
        .map((value, index) => {
            const y = peak > 0 ? height - (value / peak) * height : height / 2;

            return `${(index * step).toFixed(2)},${y.toFixed(2)}`;
        })
        .join(' ');
}
