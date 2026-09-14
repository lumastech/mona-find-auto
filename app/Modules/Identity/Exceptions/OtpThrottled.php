<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Raised when a new code is asked for before the resend window has passed.
 *
 * Each SMS costs money and a fast resend loop is how an attacker floods a
 * number, so the wait is enforced in the service rather than in a controller.
 */
class OtpThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(sprintf(
            'Wait %d more second%s before requesting another code.',
            $retryAfterSeconds,
            $retryAfterSeconds === 1 ? '' : 's',
        ));
    }

    /**
     * Present the wait as a validation error on the field that triggered it.
     */
    public function asValidationException(string $field = 'phone'): ValidationException
    {
        return ValidationException::withMessages([$field => $this->getMessage()]);
    }
}
