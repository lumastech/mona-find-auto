<?php

declare(strict_types=1);

namespace App\Integrations\Captcha;

use App\Contracts\CaptchaVerifier;
use App\Integrations\Captcha\Data\CaptchaResult;

/**
 * No challenge at all — every submission passes.
 *
 * This is the driver local development and the test suite run on, and it is
 * deliberately the default: a developer who has not been given Turnstile keys
 * should get a working signup form, not a form that rejects everyone.
 *
 * `isEnabled()` returning false is what stops the front end from rendering a
 * widget that would never load, and what keeps the launch checklist honest —
 * the production readiness check asserts the live driver is not this one.
 */
final class NullCaptchaVerifier implements CaptchaVerifier
{
    public function verify(?string $token, ?string $ip = null): CaptchaResult
    {
        return CaptchaResult::verified();
    }

    public function siteKey(): ?string
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
