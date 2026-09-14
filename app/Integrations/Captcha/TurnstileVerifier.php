<?php

declare(strict_types=1);

namespace App\Integrations\Captcha;

use App\Contracts\CaptchaVerifier;
use App\Integrations\Captcha\Data\CaptchaResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile — the real challenge.
 *
 * Everything MonaFind knows about Turnstile lives here and in the config
 * behind it. Above the CaptchaVerifier interface nothing has heard of a
 * siteverify endpoint or a secret key.
 *
 * ## Why this never throws
 *
 * A captcha sits in front of registration and password reset — the two doors
 * a locked-out person is already trying to get through. Turning a Cloudflare
 * timeout into a 500 on those forms would mean an outage at Cloudflare closes
 * MonaFind's front door. So every failure mode comes back as a value:
 * `rejected()` when Cloudflare answered and said no, `unreachable()` when we
 * could not get an answer. `CaptchaGuard` decides what to do about the second
 * one, and it is configured to let the submission through.
 *
 * ## The remote IP is sent, and it matters
 *
 * Turnstile scores a token against the address that solved it. Omitting the
 * IP does not fail the check, it just makes it weaker — a token lifted from
 * one browser and replayed from a scripted one still verifies. The address we
 * pass is whatever the request reported, which behind a proxy is only as
 * trustworthy as `TrustProxies`; that is the same trust the rate limiter
 * already places in it.
 *
 * ## One attempt, short timeout
 *
 * No retry. A challenge token is single-use and expires in minutes, so a
 * second attempt after a timeout is as likely to be refused as accepted, and
 * meanwhile a person is watching a spinner on a signup form.
 */
class TurnstileVerifier implements CaptchaVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly string $secretKey,
        private readonly string $siteKey,
        private readonly int $timeout = 5,
        private readonly ?string $expectedHostname = null,
    ) {}

    public function verify(?string $token, ?string $ip = null): CaptchaResult
    {
        if ($token === null || trim($token) === '') {
            return CaptchaResult::rejected(['missing-input-response']);
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeout)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => $ip,
                ], static fn (?string $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException $exception) {
            return CaptchaResult::unreachable(['connection-failed']);
        }

        if ($response->failed()) {
            return CaptchaResult::unreachable(['http-'.$response->status()]);
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (! array_key_exists('success', $body)) {
            return CaptchaResult::unreachable(['malformed-response']);
        }

        if ($body['success'] !== true) {
            return CaptchaResult::rejected($this->errorCodes($body));
        }

        $hostname = is_string($body['hostname'] ?? null) ? $body['hostname'] : null;

        /*
         * A token minted for another site's widget will verify against our
         * secret only if someone has our secret; checking the hostname costs
         * nothing and closes the case where a staging key leaks into a
         * production deployment.
         */
        if ($this->expectedHostname !== null && $hostname !== null && $hostname !== $this->expectedHostname) {
            return CaptchaResult::rejected(['hostname-mismatch']);
        }

        return CaptchaResult::verified(
            hostname: $hostname,
            action: is_string($body['action'] ?? null) ? $body['action'] : null,
        );
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
     * @param  array<string, mixed>  $body
     * @return array<int, string>
     */
    private function errorCodes(array $body): array
    {
        $codes = $body['error-codes'] ?? [];

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_map(strval(...), $codes));
    }
}
