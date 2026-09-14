<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Concerns\PasswordValidationRules;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Exceptions\OtpThrottled;
use App\Modules\Identity\Rules\ZambianMobileNumber;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\SessionRegistry;
use App\Modules\Identity\Support\ZambianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Password reset over SMS, for the mobile app.
 *
 * The web has this; the API did not, which meant an app user who forgot their
 * password had no way back into their account without opening a browser. The
 * coverage audit found it.
 *
 * ## The response never says whether the account exists
 *
 * `request()` answers the same way for a number MonaFind has never seen as
 * for one it has. An endpoint that answered differently would be a way to
 * test whether somebody has a MonaFind account, one number at a time — and
 * the numbers are guessable, being a ten-digit space with a known prefix.
 *
 * ## A reset revokes everything
 *
 * Somebody resetting a password may be doing it because the old one is known
 * to somebody else. Every session and every API token goes, including the one
 * that made this call.
 */
class PasswordResetController extends Controller
{
    use PasswordValidationRules;

    public function __construct(
        private readonly OtpService $otp,
        private readonly SessionRegistry $sessions,
    ) {}

    /**
     * Send a reset code to a number.
     */
    public function request(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', new ZambianMobileNumber]]);

        $phone = $this->normalisedPhone($request->string('phone')->toString());

        $user = User::query()->where('phone', $phone)->first();

        if ($user !== null) {
            try {
                $this->otp->issue($phone, OtpPurpose::PasswordReset, $user, $request->ip());
            } catch (OtpThrottled $throttled) {
                throw $throttled->asValidationException();
            }
        }

        /* Deliberately identical whether or not the account exists. */
        return ApiResponse::ok([
            'sent' => true,
            'message' => 'If that number belongs to a MonaFind account, we have sent it a reset code.',
            'expiry_minutes' => $this->otp->expiryMinutes(),
        ]);
    }

    /**
     * Check the code and set the new password.
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', new ZambianMobileNumber],
            'code' => ['required', 'string', 'digits:6'],
            'password' => $this->passwordRules(),
        ]);

        $phone = $this->normalisedPhone($request->string('phone')->toString());

        $result = $this->otp->verify($phone, OtpPurpose::PasswordReset, $validated['code']);

        if (! $result->succeeded()) {
            throw ValidationException::withMessages(['code' => $result->message()]);
        }

        $user = User::query()->where('phone', $phone)->first();

        /* The code was valid, so the account existed when it was issued. */
        if ($user === null) {
            throw ValidationException::withMessages(['phone' => 'We could not find that account.']);
        }

        DB::transaction(function () use ($user, $validated): void {
            $user->forceFill(['password' => $validated['password']])->save();

            /*
             * Everything, including the token that made this call. A reset is
             * often a response to somebody else knowing the old password.
             */
            $this->sessions->revokeEverything($user);
        });

        audit($user, 'user.password_reset', $user, null, ['via' => 'api.sms']);

        return ApiResponse::ok([
            'reset' => true,
            'message' => 'Your password has been changed. Sign in with the new one.',
        ]);
    }

    private function normalisedPhone(string $value): string
    {
        return ZambianPhone::tryParse($value)?->e164() ?? $value;
    }
}
