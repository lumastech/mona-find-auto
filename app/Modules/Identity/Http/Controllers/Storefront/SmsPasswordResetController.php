<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Storefront;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Exceptions\OtpThrottled;
use App\Modules\Identity\Rules\ZambianMobileNumber;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\SessionRegistry;
use App\Modules\Identity\Support\PhoneVerificationGate;
use App\Modules\Identity\Support\ZambianPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Resetting a password over SMS.
 *
 * Email reset stays available through Fortify; this exists because plenty of
 * buyers here reach their phone far more reliably than their inbox.
 *
 * Whether or not the number belongs to an account, the response is the same:
 * confirming which numbers are registered would turn this into a way to
 * enumerate the platform's users.
 *
 * The whole flow is closed until there is an SMS gateway to carry the code
 * (Support\PhoneVerificationGate). A reset screen that silently sends
 * nothing is worse than no screen: it looks like the account is unreachable,
 * and the email reset beside it does work.
 */
class SmsPasswordResetController extends Controller
{
    use PasswordValidationRules;

    public function __construct(
        private readonly OtpService $otp,
        private readonly SessionRegistry $sessions,
    ) {}

    /**
     * The E.164 form of a number the rules have already accepted.
     *
     * ZambianMobileNumber has passed by the time this runs, so a failure here
     * would mean the rule and the parser disagree — which is a bug, not a
     * user error.
     */
    private function normalisedPhone(string $phone): string
    {
        $parsed = ZambianPhone::tryParse($phone);

        if ($parsed === null) {
            throw ValidationException::withMessages([
                'phone' => 'That is not a Zambian mobile number.',
            ]);
        }

        return $parsed->e164();
    }

    /**
     * Refuse the whole flow while a code cannot be delivered.
     */
    private function ensureSmsIsAvailable(): void
    {
        abort_if(
            PhoneVerificationGate::disabled(),
            HttpResponse::HTTP_NOT_FOUND,
            'Password reset by SMS is not available yet.',
        );
    }

    public function show(Request $request): Response
    {
        $this->ensureSmsIsAvailable();

        return Inertia::render('auth/ForgotPasswordSms', [
            'status' => $request->session()->get('status'),
            'phone' => $request->session()->get('reset_phone'),
        ]);
    }

    /**
     * Send a reset code to a phone number.
     */
    public function send(Request $request): RedirectResponse
    {
        $this->ensureSmsIsAvailable();

        $validated = $request->validate([
            'phone' => ['required', 'string', new ZambianMobileNumber],
        ]);

        $phone = $this->normalisedPhone($request->string('phone')->toString());
        $user = User::query()->where('phone', $phone)->first();

        if ($user !== null) {
            try {
                $this->otp->issue($phone, OtpPurpose::PasswordReset, $user, $request->ip());
            } catch (OtpThrottled $throttled) {
                throw $throttled->asValidationException();
            }
        }

        return to_route('password.sms.reset')
            ->with('reset_phone', $phone)
            ->with('status', 'If that number belongs to a MonaFind account, we have sent it a reset code.');
    }

    public function edit(Request $request): Response
    {
        $this->ensureSmsIsAvailable();

        return Inertia::render('auth/ResetPasswordSms', [
            'phone' => $request->session()->get('reset_phone'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Check the code and set the new password.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->ensureSmsIsAvailable();

        $validated = $request->validate([
            'phone' => ['required', 'string', new ZambianMobileNumber],
            'code' => ['required', 'string', 'digits:6'],
            'password' => $this->passwordRules(),
        ]);

        $phone = $this->normalisedPhone($request->string('phone')->toString());

        $result = $this->otp->verify($phone, OtpPurpose::PasswordReset, $request->string('code')->toString());

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

            /* A reset means the old password may be known to somebody else. */
            $this->sessions->revokeEverything($user);
        });

        audit($user, 'user.password_reset', $user, null, ['via' => 'sms']);

        return to_route('login')->with('status', 'Your password has been reset. Log in with your new password.');
    }
}
