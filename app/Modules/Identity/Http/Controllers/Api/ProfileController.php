<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Concerns\PasswordValidationRules;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\SessionRegistry;
use App\Modules\Identity\Support\AccountFieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Editing your own account from the app.
 *
 * ## Changing the phone number re-opens verification
 *
 * A number is the account's contact point for an order and the destination
 * for a seller's payout, so a changed number is an unproven number. The
 * verification stamp is cleared and the app is told to send the person back
 * through `/api/v1/phone/verify` — which is why that endpoint had to exist
 * before this one could.
 *
 * ## Changing the password ends every other session
 *
 * Not this one: the token that made the call keeps working, because signing
 * somebody out of the app the instant they change their password is a
 * confusing way to confirm it worked. Every OTHER session and token goes,
 * which is the point of the exercise.
 */
class ProfileController extends Controller
{
    use AccountFieldRules, InteractsWithCurrentUser, PasswordValidationRules;

    public function __construct(private readonly SessionRegistry $sessions) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(new UserResource($this->currentUser($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = validator($this->normalisePhone($request->all()), [
            ...$this->identityRules($user->getKey()),
            ...$this->addressRules(required: false),
        ])->validate();

        $before = $user->only(['first_name', 'last_name', 'email', 'phone', 'city_id']);

        $phoneChanged = $validated['phone'] !== $user->phone;
        $emailChanged = $validated['email'] !== $user->email;

        $user->fill($validated);

        if ($phoneChanged) {
            /* An unproven number: see the class docblock. */
            $user->forceFill(['phone_verified_at' => null]);
        }

        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null]);
        }

        $user->save();

        audit($user, 'user.profile_updated', $user, $before, $user->only(array_keys($before)));

        return ApiResponse::ok([
            'user' => new UserResource($user->refresh()),
            /* The app reads these to decide whether to route to a verification screen. */
            'phone_verification_required' => $phoneChanged,
            'email_verification_required' => $emailChanged,
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => $this->passwordRules(),
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That password is not correct.',
            ]);
        }

        $user->forceFill(['password' => $validated['password']])->save();

        /* Everything except the caller — see the class docblock. */
        $this->sessions->revokeOthers(
            $user,
            $request->hasSession() ? $request->session()->getId() : null,
            $user->currentAccessToken(),
        );

        audit($user, 'user.password_changed', $user, null, ['via' => 'api']);

        return ApiResponse::ok(['updated' => true]);
    }
}
