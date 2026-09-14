<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\OtpVerificationResult;
use App\Modules\Identity\Exceptions\OtpThrottled;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Notifications\OtpNotification;
use App\Modules\Identity\Support\ZambianPhone;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Issues and checks the six-digit codes sent over SMS.
 *
 * Three rules make a short numeric code safe, and all three live here rather
 * than in the controllers that happen to need them:
 *
 * - a code expires (identity.otp_expiry_minutes);
 * - a code dies after a few wrong guesses (identity.otp_max_attempts);
 * - a number cannot be flooded with new codes (identity.otp_resend_seconds).
 *
 * Only the hash of a code is ever stored, so a database dump does not let
 * anyone complete a verification.
 */
class OtpService
{
    private const CODE_LENGTH = 6;

    /**
     * Send a fresh code, replacing any code still outstanding for the same
     * number and purpose.
     *
     * @throws OtpThrottled when the resend window has not passed yet.
     */
    public function issue(string $phone, OtpPurpose $purpose, ?User $user = null, ?string $requestIp = null): PhoneVerification
    {
        $phone = $this->normalise($phone);

        $this->guardAgainstResendFlood($phone, $purpose);

        /* Only ever one live code per number and purpose. */
        PhoneVerification::query()
            ->outstanding($phone, $purpose)
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();
        $expiresInMinutes = $this->expiryMinutes();

        $verification = PhoneVerification::query()->create([
            'user_id' => $user?->getKey(),
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($expiresInMinutes),
            'last_sent_at' => now(),
            'request_ip' => $requestIp,
        ]);

        /*
         * On demand rather than to the user: at registration there is no
         * account yet, and a password reset deliberately does not look one up
         * from an unauthenticated request.
         */
        Notification::route('sms', $phone)
            ->notify(new OtpNotification($code, $purpose, $expiresInMinutes));

        return $verification;
    }

    /**
     * Check a code a person typed. A correct code is consumed, so the same
     * code can never be used twice.
     */
    public function verify(string $phone, OtpPurpose $purpose, string $code): OtpVerificationResult
    {
        $verification = $this->outstanding($this->normalise($phone), $purpose);

        if ($verification === null) {
            return OtpVerificationResult::NotFound;
        }

        if ($verification->isExpired()) {
            return OtpVerificationResult::Expired;
        }

        $maxAttempts = $this->maxAttempts();

        if (! $verification->hasAttemptsLeft($maxAttempts)) {
            return OtpVerificationResult::TooManyAttempts;
        }

        if (Hash::check($code, $verification->code_hash)) {
            $verification->forceFill(['consumed_at' => now()])->save();

            return OtpVerificationResult::Verified;
        }

        $verification->increment('attempts');

        /* The guess that uses up the last attempt burns the code outright. */
        if (! $verification->hasAttemptsLeft($maxAttempts)) {
            $verification->forceFill(['consumed_at' => now()])->save();

            return OtpVerificationResult::TooManyAttempts;
        }

        return OtpVerificationResult::Invalid;
    }

    /**
     * The live code for a number and purpose, if there is one.
     */
    public function outstanding(string $phone, OtpPurpose $purpose): ?PhoneVerification
    {
        return PhoneVerification::query()
            ->outstanding($this->normalise($phone), $purpose)
            ->first();
    }

    /**
     * Seconds a caller must wait before another code may be sent.
     */
    public function secondsUntilResend(string $phone, OtpPurpose $purpose): int
    {
        return $this->outstanding($phone, $purpose)?->secondsUntilResend($this->resendSeconds()) ?? 0;
    }

    public function expiryMinutes(): int
    {
        return max(1, settings()->integer('identity.otp_expiry_minutes', 10));
    }

    public function maxAttempts(): int
    {
        return max(1, settings()->integer('identity.otp_max_attempts', 3));
    }

    public function resendSeconds(): int
    {
        return max(0, settings()->integer('identity.otp_resend_seconds', 60));
    }

    /**
     * @throws OtpThrottled
     */
    private function guardAgainstResendFlood(string $phone, OtpPurpose $purpose): void
    {
        $wait = $this->secondsUntilResend($phone, $purpose);

        if ($wait > 0) {
            throw new OtpThrottled($wait);
        }
    }

    /**
     * Six digits, leading zeros included, from a cryptographic source.
     */
    private function generateCode(): string
    {
        return str_pad(
            (string) random_int(0, (10 ** self::CODE_LENGTH) - 1),
            self::CODE_LENGTH,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * Codes are looked up by the stored E.164 form, whatever shape the caller
     * had the number in.
     */
    private function normalise(string $phone): string
    {
        return ZambianPhone::tryParse($phone)?->e164() ?? $phone;
    }
}
