<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Integrations\Captcha\Data\CaptchaResult;

/**
 * Proof that a public form was submitted by a person.
 *
 * Cloudflare Turnstile in production, a fake elsewhere so tests never touch
 * the network. Public forms — registration, password reset, the guest-facing
 * enquiry and quotation forms — carry a token that only this boundary knows
 * how to judge.
 *
 * The verifier never throws on a failed challenge: a wrong or expired token
 * is an ordinary validation failure, not an exception. It reports a network
 * or configuration problem the same way, as an unsuccessful result carrying
 * error codes, so that a Cloudflare outage becomes a decision the caller
 * makes (see `CaptchaGuard::failOpen()`) rather than a 500 on the signup form.
 */
interface CaptchaVerifier
{
    /**
     * Judge one challenge response.
     *
     * `$token` is whatever the widget put in the form; null and empty are
     * both "no challenge was solved" and must fail.
     */
    public function verify(?string $token, ?string $ip = null): CaptchaResult;

    /**
     * The public site key the browser widget needs, or null when this
     * deployment has no challenge configured.
     */
    public function siteKey(): ?string;

    /**
     * Whether this verifier actually challenges anyone. False for the null
     * driver, which is what local development and the test suite run on.
     */
    public function isEnabled(): bool;
}
