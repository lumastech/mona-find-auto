<?php

declare(strict_types=1);

namespace App\Integrations\Captcha;

use App\Contracts\CaptchaVerifier;
use App\Integrations\Captcha\Data\CaptchaResult;
use App\Integrations\Support\RecordsCalls;

/**
 * The captcha a test drives.
 *
 * Enabled by default — a test that binds this is asking to exercise the
 * challenged path, which is the opposite of what NullCaptchaVerifier offers.
 * Tokens are accepted unless a test says otherwise, so a feature test that
 * does not care about the captcha keeps passing once one appears on its form.
 */
final class FakeCaptchaVerifier implements CaptchaVerifier
{
    use RecordsCalls;

    private bool $shouldPass = true;

    private bool $shouldBeReachable = true;

    public function __construct(private readonly ?string $siteKey = 'test-site-key') {}

    public function verify(?string $token, ?string $ip = null): CaptchaResult
    {
        $this->recordCall('verify', ['token' => $token, 'ip' => $ip]);

        if (! $this->shouldBeReachable) {
            return CaptchaResult::unreachable(['connection-failed']);
        }

        if (! $this->shouldPass) {
            return CaptchaResult::rejected(['invalid-input-response']);
        }

        if ($token === null || trim($token) === '') {
            return CaptchaResult::rejected(['missing-input-response']);
        }

        return CaptchaResult::verified(hostname: 'localhost');
    }

    public function siteKey(): ?string
    {
        return $this->siteKey;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Reject every token from now on, as Cloudflare would for a forged one.
     */
    public function reject(): self
    {
        $this->shouldPass = false;
        $this->shouldBeReachable = true;

        return $this;
    }

    /**
     * Behave as though Cloudflare is down, so a test can prove the guard
     * fails open rather than locking the signup form.
     */
    public function goOffline(): self
    {
        $this->shouldBeReachable = false;

        return $this;
    }
}
