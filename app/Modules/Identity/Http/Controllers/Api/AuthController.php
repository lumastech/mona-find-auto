<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Actions\RegisterUser;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Support\ZambianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token authentication for the mobile app.
 *
 * The web session flow (Fortify) and this share their actions, so a rule
 * enforced in one is enforced in the other: same registration, same account
 * statuses, same mandatory two-factor for staff.
 */
class AuthController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly RegisterUser $register) {}

    /**
     * Register an account and return a token for it.
     */
    public function register(Request $request): JsonResponse
    {
        $user = $this->register->handle($request->all(), via: 'api', requestIp: $request->ip());

        return ApiResponse::created([
            'user' => new UserResource($user),
            'token' => $this->issueToken($user, $request),
        ]);
    }

    /**
     * Exchange an email address or phone number plus a password for a token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $this->findByIdentifier($validated['login']);

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            /* One message for both cases: which half was wrong is not the caller's business. */
            throw ValidationException::withMessages([
                'login' => 'Those credentials do not match our records.',
            ]);
        }

        if ($user->status->forcesLogout()) {
            return ApiResponse::error(
                'account_blocked',
                $user->status->blockedMessage($user->status_reason),
                ['status' => $user->status->value],
                Response::HTTP_FORBIDDEN,
            );
        }

        /*
         * Staff hold powers a stolen phone must not inherit, and there is no
         * TOTP challenge over this endpoint yet, so they authenticate on the
         * web where Fortify can challenge them.
         */
        if ($user->requiresTwoFactor()) {
            return ApiResponse::error(
                'two_factor_required',
                'Staff accounts sign in on the web, where two-factor authentication is enforced.',
                [],
                Response::HTTP_FORBIDDEN,
            );
        }

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        audit($user, 'user.logged_in', $user, null, ['via' => 'api']);

        return ApiResponse::ok([
            'user' => new UserResource($user),
            'token' => $this->issueToken($user, $request),
        ]);
    }

    /**
     * Drop the token this request was made with.
     */
    public function logout(Request $request): JsonResponse
    {
        /* Only this device's token; the account's other devices stay signed in. */
        $this->currentUser($request)->currentAccessToken()->delete();

        return ApiResponse::noContent();
    }

    /**
     * The account behind the current token.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok(new UserResource($this->currentUser($request)));
    }

    /**
     * Mint a Sanctum token named after the calling device, so somebody can
     * recognise and revoke it from their session list later.
     */
    private function issueToken(User $user, Request $request): string
    {
        $name = $request->string('device_name')->trim()->toString();

        if ($name === '') {
            $name = Str::limit((string) $request->userAgent(), 100, '') ?: 'Mobile app';
        }

        return $user->createToken($name)->plainTextToken;
    }

    private function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        $phone = str_contains($identifier, '@')
            ? null
            : ZambianPhone::tryParse($identifier)?->e164();

        return User::query()
            ->when(
                $phone !== null,
                fn ($query) => $query->where('phone', $phone),
                fn ($query) => $query->where('email', Str::lower($identifier)),
            )
            ->first();
    }
}
