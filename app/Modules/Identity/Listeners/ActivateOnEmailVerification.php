<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Models\User;
use App\Modules\Identity\Services\AccountModerationService;
use App\Modules\Identity\Support\PhoneVerificationGate;
use Illuminate\Auth\Events\Verified;

/**
 * Activates a Pending account when it proves its email address.
 *
 * This is the stand-in for the phone OTP while there is no SMS gateway. An
 * account has to prove SOMETHING before it can trade, and with no way to
 * send a code the only proof left is the email link Fortify already sends —
 * which the seller and admin areas require anyway (the `verified` middleware
 * in bootstrap/app.php).
 *
 * When the gateway arrives and PhoneVerificationGate is switched on, this
 * stands down: proving a number is the stronger claim, and an account that
 * only confirmed an email address should not be trading on it.
 */
class ActivateOnEmailVerification
{
    public function __construct(private readonly AccountModerationService $accounts) {}

    public function handle(Verified $event): void
    {
        if (PhoneVerificationGate::enabled()) {
            return;
        }

        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->accounts->activateAfterVerification($user, 'Email address verified.');
    }
}
