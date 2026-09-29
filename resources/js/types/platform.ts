import type { CurrencyConfig } from '@/lib/money';

/**
 * Platform-wide values shared with every Inertia page.
 */
export type Platform = {
    currency: CurrencyConfig;
    /** IANA name used when rendering timestamps, e.g. "Africa/Lusaka". */
    timezone: string;
    /**
     * Whether phone numbers are proven over SMS on this deployment.
     *
     * False until the SMS gateway is live: numbers are still collected, no
     * code is sent, and an account is activated by its email address
     * instead. Anything that depends on a delivered code — "Reset by SMS",
     * the "not confirmed yet" note — renders as nothing while it is false.
     */
    phoneVerification: boolean;
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
