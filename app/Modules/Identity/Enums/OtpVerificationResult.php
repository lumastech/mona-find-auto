<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Why an entered code was or was not accepted.
 *
 * Controllers map these onto messages; keeping them apart from the message
 * text is what lets the API and the web UI phrase the same outcome
 * differently.
 */
enum OtpVerificationResult: string
{
    case Verified = 'verified';

    /** No outstanding code for this number and purpose. */
    case NotFound = 'not_found';

    /** The code exists but its ten minutes are up. */
    case Expired = 'expired';

    /** Wrong code, and attempts remain. */
    case Invalid = 'invalid';

    /** Wrong code once too often; the code is burnt and a new one is needed. */
    case TooManyAttempts = 'too_many_attempts';

    public function succeeded(): bool
    {
        return $this === self::Verified;
    }

    public function message(): string
    {
        return match ($this) {
            self::Verified => 'Phone number verified.',
            self::NotFound => 'Request a new code — we have no outstanding one for this number.',
            self::Expired => 'That code has expired. Request a new one.',
            self::Invalid => 'That code is not correct.',
            self::TooManyAttempts => 'Too many incorrect attempts. Request a new code.',
        };
    }
}
