<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Exceptions\OtpThrottled;
use App\Modules\Identity\Services\AccountModerationService;
use App\Modules\Identity\Services\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Proving that the phone number on an account is reachable.
 *
 * This matters more than it looks: a Zambian buyer is contacted about an
 * order by phone, and a seller's payout is routed to a mobile-money wallet on
 * that number.
 */
class PhoneVerificationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly OtpService $otp,
        private readonly AccountModerationService $accounts,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->currentUser($request);

        if ($user->hasVerifiedPhone()) {
            return to_route('dashboard');
        }

        /* A social signup has no number yet; it is asked for one first. */
        if ($user->phone === null) {
            return to_route('phone.setup');
        }

        return Inertia::render('auth/VerifyPhone', [
            'phone' => $user->phoneNumber()?->masked(),
            'status' => $request->session()->get('status'),
            'resendAvailableIn' => $this->otp->secondsUntilResend($user->phone, OtpPurpose::PhoneVerification),
            'expiryMinutes' => $this->otp->expiryMinutes(),
        ]);
    }

    /**
     * Send another code.
     */
    public function send(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);

        if ($user->hasVerifiedPhone()) {
            return to_route('dashboard');
        }

        if ($user->phone === null) {
            return to_route('phone.setup');
        }

        try {
            $this->otp->issue($user->phone, OtpPurpose::PhoneVerification, $user, $request->ip());
        } catch (OtpThrottled $throttled) {
            throw $throttled->asValidationException('code');
        }

        return back()->with('status', 'We sent a new code to '.$user->phoneNumber()?->masked().'.');
    }

    /**
     * Check the code the person typed.
     */
    public function verify(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);

        if ($user->phone === null) {
            return to_route('phone.setup');
        }

        $code = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ])['code'];

        $result = $this->otp->verify($user->phone, OtpPurpose::PhoneVerification, is_string($code) ? $code : '');

        if (! $result->succeeded()) {
            throw ValidationException::withMessages(['code' => $result->message()]);
        }

        $user->forceFill(['phone_verified_at' => now()])->save();

        audit($user, 'user.phone_verified', $user, null, ['phone' => $user->phone]);

        /* A pending account becomes active the moment its number is proven. */
        $this->accounts->activateAfterVerification($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Phone number verified.')]);

        return to_route('dashboard');
    }
}
