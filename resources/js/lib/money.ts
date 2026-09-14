/**
 * Money helpers for the browser.
 *
 * Amounts cross the wire as an integer number of ngwee (ZMW minor units) and
 * stay integers here. Nothing in this file divides an amount before it is
 * formatted, so a price can never drift by a ngwee on its way to the screen.
 */

export type CurrencyConfig = {
    code: string;
    symbol: string;
    minor_unit_name: string;
    minor_units_per_major: number;
    decimals: number;
};

export const defaultCurrency: CurrencyConfig = {
    code: 'ZMW',
    symbol: 'K',
    minor_unit_name: 'ngwee',
    minor_units_per_major: 100,
    decimals: 2,
};

/**
 * Split an amount into its whole and fractional parts without floating point.
 */
function partsOf(ngwee: number, currency: CurrencyConfig) {
    const amount = Math.trunc(ngwee);
    const perMajor = currency.minor_units_per_major;
    const absolute = Math.abs(amount);

    return {
        negative: amount < 0,
        major: Math.trunc(absolute / perMajor),
        minor: absolute % perMajor,
    };
}

/**
 * "1299.05" — the plain decimal form, for inputs and copyable values.
 */
export function toDecimalString(
    ngwee: number,
    currency: CurrencyConfig = defaultCurrency,
): string {
    const { negative, major, minor } = partsOf(ngwee, currency);

    return `${negative ? '-' : ''}${major}.${String(minor).padStart(currency.decimals, '0')}`;
}

/**
 * "K 1,299.05" — the display form.
 */
export function formatMoney(
    ngwee: number,
    currency: CurrencyConfig = defaultCurrency,
    options: { withSymbol?: boolean } = {},
): string {
    const { withSymbol = true } = options;
    const { negative, major, minor } = partsOf(ngwee, currency);

    const grouped = major.toLocaleString('en-ZM');
    const fraction = String(minor).padStart(currency.decimals, '0');
    const amount = `${grouped}.${fraction}`;

    return withSymbol
        ? `${negative ? '-' : ''}${currency.symbol} ${amount}`
        : `${negative ? '-' : ''}${amount}`;
}

/**
 * Read a typed amount from an API payload, which may send either the integer
 * or the full Money envelope produced by App\Support\Money\Money.
 */
export type MoneyPayload =
    | number
    | {
          ngwee: number;
          currency?: string;
          formatted?: string;
          decimal?: string;
      };

export function ngweeOf(amount: MoneyPayload): number {
    return typeof amount === 'number' ? amount : amount.ngwee;
}
