<?php

declare(strict_types=1);

namespace App\Integrations\Captcha\Data;

/**
 * The verdict on one challenge response.
 *
 * `$reachable` is the field that matters operationally and is easy to miss:
 * a token Cloudflare rejected and a Cloudflare we could not reach are both
 * "not verified", but only the first is the submitter's fault. Keeping them
 * apart is what lets the guard fail open on an outage without failing open
 * on a forged token.
 */
final readonly class CaptchaResult
{
    /**
     * @param  array<int, string>  $errorCodes
     */
    private function __construct(
        public bool $verified,
        public bool $reachable,
        public array $errorCodes = [],
        public ?string $hostname = null,
        public ?string $action = null,
    ) {}

    public static function verified(?string $hostname = null, ?string $action = null): self
    {
        return new self(verified: true, reachable: true, hostname: $hostname, action: $action);
    }

    /**
     * The provider answered and said no.
     *
     * @param  array<int, string>  $errorCodes
     */
    public static function rejected(array $errorCodes = []): self
    {
        return new self(verified: false, reachable: true, errorCodes: $errorCodes);
    }

    /**
     * We could not get an answer — timeout, DNS, 5xx, malformed body.
     *
     * @param  array<int, string>  $errorCodes
     */
    public static function unreachable(array $errorCodes = []): self
    {
        return new self(verified: false, reachable: false, errorCodes: $errorCodes);
    }

    /**
     * A single short string for logs and audit rows.
     */
    public function reason(): string
    {
        if ($this->verified) {
            return 'verified';
        }

        if ($this->errorCodes !== []) {
            return implode(',', $this->errorCodes);
        }

        return $this->reachable ? 'rejected' : 'unreachable';
    }
}
