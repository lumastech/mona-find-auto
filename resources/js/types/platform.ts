import type { CurrencyConfig } from '@/lib/money';

/**
 * Platform-wide values shared with every Inertia page.
 */
export type Platform = {
    currency: CurrencyConfig;
    /** IANA name used when rendering timestamps, e.g. "Africa/Lusaka". */
    timezone: string;
};

/**
 * What the browser is told about the captcha.
 *
 * The site key only — the secret never leaves the server. `siteKey` is null
 * on a deployment that challenges nobody, which is how a widget knows to
 * render as nothing rather than as a box that will never load.
 */
export type Captcha = {
    siteKey: string | null;
    /** The forms that carry a challenge, e.g. ["register", "password-reset"]. */
    forms: string[];
};
