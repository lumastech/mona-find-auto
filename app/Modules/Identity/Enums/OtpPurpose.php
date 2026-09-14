<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * What a one-time code was issued for.
 *
 * A code is only ever accepted for the purpose it was issued for, so a phone
 * verification code can never be replayed to reset a password.
 */
enum OtpPurpose: string
{
    /** Proving control of the phone number on the account. */
    case PhoneVerification = 'phone_verification';

    /** Resetting a password over SMS instead of email. */
    case PasswordReset = 'password_reset';

    public function message(string $code, int $expiresInMinutes): string
    {
        return match ($this) {
            self::PhoneVerification => "MonaFind: {$code} is your verification code. It expires in {$expiresInMinutes} minutes. Never share it.",
            self::PasswordReset => "MonaFind: {$code} is your password reset code. It expires in {$expiresInMinutes} minutes. Never share it.",
        };
    }
}
