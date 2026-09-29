<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * Whether this deployment can prove a phone number over SMS.
 *
 * MonaFind has no SMS gateway yet, and a six-digit code sent through a
 * provider that cannot deliver is worse than no code at all: registration
 * would end at a screen asking for something nobody can receive, and every
 * account would sit in Pending for good.
 *
 * So the OTP is behind one switch (`integrations.sms.phone_verification`,
 * env PHONE_VERIFICATION_ENABLED) and everything that depends on it asks
 * here. While it is off:
 *
 * - registration still collects and validates a Zambian mobile number, it
 *   just sends no code — nothing has to be re-collected later;
 * - a verified email address activates the account instead of the OTP
 *   (Listeners\ActivateOnEmailVerification);
 * - the phone verification screens and `phone.verified` middleware step
 *   aside rather than block;
 * - password reset over SMS closes, leaving Fortify's email reset.
 *
 * Turning it on restores the SMS flow with no other change, which is why
 * this is a config flag rather than a `settings()` row: it describes what
 * the deployment can physically do, like the SMS driver beside it, not a
 * policy for administrators to tune.
 */
final class PhoneVerificationGate
{
    /**
     * Whether phone numbers are verified over SMS on this deployment.
     */
    public static function enabled(): bool
    {
        return (bool) config('integrations.sms.phone_verification', false);
    }

    /**
     * The reading most callers want: there is no gateway, so step aside.
     */
    public static function disabled(): bool
    {
        return ! self::enabled();
    }
}
