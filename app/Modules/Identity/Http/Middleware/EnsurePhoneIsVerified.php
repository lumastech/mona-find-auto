<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the actions that need a reachable phone number — checkout, seller
 * onboarding, mechanic endorsement.
 *
 * Browsing never needs this; being contactable about an order does.
 */
class EnsurePhoneIsVerified
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
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
