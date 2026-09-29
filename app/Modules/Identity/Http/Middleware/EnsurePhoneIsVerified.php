<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Support\PhoneVerificationGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the actions that need a reachable phone number — checkout, seller
 * onboarding, mechanic endorsement.
 *
 * Browsing never needs this; being contactable about an order does.
 *
 * With no SMS gateway there is nothing an account could do to satisfy this,
 * so it steps aside rather than turning every guarded action into a dead
 * end — see Support\PhoneVerificationGate.
 */
class EnsurePhoneIsVerified
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (PhoneVerificationGate::disabled()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && $user->hasVerifiedPhone()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, 'Verify your phone number to continue.');
        }

        return redirect()
            ->route('phone.verify')
            ->with('status', 'Verify your phone number to continue.');
    }
}
