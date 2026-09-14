<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Exceptions\OtpThrottled;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\AccountModerationService;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Support\AccountFieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone verification for the mobile app.
 *
 * ## Why this endpoint had to exist
 *
 * `/api/v1/auth/register` creates an account in Pending, and a Pending
 * account becomes Active only when its phone number is proven. Until this
 * controller existed, the JSON API could create an account and had no way to
 * finish one — a mobile client could register and then had nowhere to go. The
 * API coverage audit in docs/API_COVERAGE.md is what found it.
 *
 * ## Setting the number as well as proving it
 *
 * `store()` is the equivalent of the web's phone-setup screen. A social
 * sign-in arrives with no number at all, and an account that mistyped one
 * needs to correct it before a code can reach them.
 */
class PhoneVerificationController extends Controller
{
    use AccountFieldRules, InteractsWithCurrentUser;

    public function __construct(
        private readonly OtpService $otp,
        private readonly AccountModerationService $accounts,
    ) {}

    /**
     * Where verification stands for the caller.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return ApiResponse::ok([
            /* Masked: an app showing the full number teaches nothing and leaks it to a screenshot. */
            'phone' => $user->phoneNumber()?->masked(),
            'verified' => $user->hasVerifiedPhone(),
            'resend_available_in' => $user->phone === null
                ? 0
                : $this->otp->secondsUntilResend($user->phone, OtpPurpose::PhoneVerification),
            'expiry_minutes' => $this->otp->expiryMinutes(),
            'attempts_allowed' => $this->otp->maxAttempts(),
        ]);
    }

    /**
     * Set or correct the number, and send a code to it.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if ($user->hasVerifiedPhone()) {
            return ApiResponse::error(
                'already_verified',
                'This account already has a verified phone number.',
                [],
                Response::HTTP_CONFLICT,
            );
        }

        $validated = validator(
            $this->normalisePhone($request->all()),
            ['phone' => $this->phoneRules($user->getKey())],
        )->validate();

        $phone = (string) $validated['phone'];

        $user->forceFill(['phone' => $phone])->save();

        audit($user, 'user.phone_set', $user, null, ['phone' => $phone]);

        return $this->issueCode($phone, $request);
    }

    /**
     * Send another code.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if ($user->hasVerifiedPhone()) {
            return ApiResponse::ok(['verified' => true]);
        }

        if ($user->phone === null) {
            return ApiResponse::error(
                'phone_required',
                'Set a phone number before asking for a code.',
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->issueCode($user->phone, $request);
    }

    /**
     * Check the code, and activate the account if it is right.
     */
    public function verify(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if ($user->phone === null) {
            return ApiResponse::error(
                'phone_required',
                'Set a phone number before verifying one.',
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']]);

        $result = $this->otp->verify($user->phone, OtpPurpose::PhoneVerification, $validated['code']);

        if (! $result->succeeded()) {
            /*
             * A validation failure rather than a bespoke error code: the
             * ApiExceptionRenderer already maps it into the envelope, and the
             * app can show it against the field the person typed into.
             */
            throw ValidationException::withMessages(['code' => $result->message()]);
        }

        $user->forceFill(['phone_verified_at' => now()])->save();

        audit($user, 'user.phone_verified', $user, null, ['phone' => $user->phone]);

        /* A pending account becomes active the moment its number is proven. */
        $this->accounts->activateAfterVerification($user);

        return ApiResponse::ok([
            'verified' => true,
            'user' => new UserResource($user->refresh()),
        ]);
    }

    /**
     * Issue a code, turning a throttle into the field error the app shows.
     */
    private function issueCode(string $phone, Request $request): JsonResponse
    {
        try {
            $this->otp->issue($phone, OtpPurpose::PhoneVerification, $this->currentUser($request), $request->ip());
        } catch (OtpThrottled $throttled) {
            throw $throttled->asValidationException('code');
        }

        return ApiResponse::ok([
            'sent' => true,
            'resend_available_in' => $this->otp->secondsUntilResend($phone, OtpPurpose::PhoneVerification),
            'expiry_minutes' => $this->otp->expiryMinutes(),
        ]);
    }
}
