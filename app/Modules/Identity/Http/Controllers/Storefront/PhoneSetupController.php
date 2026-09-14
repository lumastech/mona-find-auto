<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Exceptions\OtpThrottled;
use App\Modules\Identity\Rules\ZambianMobileNumber;
use App\Modules\Identity\Services\LocationDirectory;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Support\AccountFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finishing an account that started life as a Google or Facebook login.
 *
 * Those give us a name and an email address and nothing else, so this is
 * where the phone number and address the platform actually needs are
 * collected, followed by the usual SMS verification.
 */
class PhoneSetupController extends Controller
{
    use AccountFieldRules, InteractsWithCurrentUser;

    public function __construct(
        private readonly OtpService $otp,
        private readonly LocationDirectory $locations,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->currentUser($request);

        if ($user->hasVerifiedPhone()) {
            return to_route('dashboard');
        }

        if ($user->phone !== null) {
            return to_route('phone.verify');
        }

        return Inertia::render('auth/CompleteProfile', [
            'provinces' => $this->locations->provincesWithCities(),
        ]);
    }

    /**
     * Save the number and address, then send the first code.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);

        /* Normalise first: uniqueness is checked against the E.164 form the column holds. */
        $request->merge($this->normalisePhone($request->all()));

        $validated = $request->validate([
            'phone' => [
                'required',
                'string',
                new ZambianMobileNumber,
                Rule::unique('users', 'phone')->ignore($user->getKey()),
            ],
            ...$this->addressRules(),
        ]);

        $phone = $request->string('phone')->toString();

        $user->forceFill([...$validated, 'phone_verified_at' => null])->save();

        audit($user, 'user.phone_set', $user, null, ['phone' => $phone]);

        try {
            $this->otp->issue($phone, OtpPurpose::PhoneVerification, $user, $request->ip());
        } catch (OtpThrottled) {
            // A code is already in flight for this number; that one still works.
        }

        return to_route('phone.verify');
    }
}
