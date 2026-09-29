<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\SocialProvider;
use App\Modules\Identity\Services\SocialAuthService;
use App\Modules\Identity\Support\PhoneVerificationGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in with Google or Facebook.
 *
 * A social login is a way to prove an email address, not a way around the
 * platform's own checks: a new account still has to give the Zambian phone
 * number and address the platform needs, and — where there is an SMS gateway
 * to prove it with — still has to verify that number before it can trade.
 */
class SocialAuthController extends Controller
{
    public function __construct(private readonly SocialAuthService $social) {}

    /**
     * Hand the visitor off to the provider.
     */
    public function redirect(string $provider): SymfonyRedirectResponse
    {
        return Socialite::driver($this->provider($provider)->value)->redirect();
    }

    /**
     * Take the visitor back from the provider.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $driver = $this->provider($provider);

        try {
            $oauthUser = Socialite::driver($driver->value)->user();
        } catch (InvalidStateException) {
            /* A stale or replayed callback — start again rather than guess. */
            return to_route('login')->with('status', 'That sign-in link expired. Please try again.');
        }

        $user = $this->social->resolve($driver, $oauthUser);

        if ($user->status->forcesLogout()) {
            return to_route('login')->with('status', $user->status->blockedMessage($user->status_reason));
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        audit($user, 'user.logged_in', $user, null, ['via' => $driver->value]);

        /* A social account has no phone number yet; that is the next thing it needs. */
        if (PhoneVerificationGate::enabled()) {
            return $user->hasVerifiedPhone()
                ? redirect()->intended(route('dashboard'))
                : to_route('phone.setup');
        }

        /* No gateway: only a MISSING number still stands in the way. */
        return $user->phone === null
            ? to_route('phone.setup')
            : redirect()->intended(route('dashboard'));
    }

    /**
     * Reject a provider this deployment has no credentials for, rather than
     * letting Socialite fail with a configuration error.
     */
    private function provider(string $provider): SocialProvider
    {
        $resolved = SocialProvider::tryFrom($provider);

        abort_if(
            $resolved === null || ! $resolved->isConfigured(),
            Response::HTTP_NOT_FOUND,
            'That sign-in method is not available.',
        );

        return $resolved;
    }
}
