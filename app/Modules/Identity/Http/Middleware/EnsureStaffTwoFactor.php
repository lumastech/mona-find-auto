<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory for moderators, finance and platform
 * administrators.
 *
 * Fortify challenges a staff member who has already enrolled. This closes the
 * other half: a staff account that has never set 2FA up can reach nothing at
 * all — not the console, not the seller portal, not the storefront — except
 * the screens that enrol it. Staff hold roles a stolen password must not
 * inherit, so an unenrolled staff account is treated as half signed in.
 */
class EnsureStaffTwoFactor
{
    /**
     * The screens an unenrolled staff account may still reach: setting 2FA up,
     * confirming a password to get there, verifying an email address, and
     * signing out.
     *
     * @var array<int, string>
     */
    private const ENROLMENT_ROUTES = [
        'security.edit',
        'two-factor.*',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'passkey.*',
        'verification.*',
        'logout',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->mustEnrolInTwoFactor()) {
            return $next($request);
        }

        /* The enrolment path itself must stay open, or there is no way out. */
        if ($request->routeIs(...self::ENROLMENT_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, 'Set up two-factor authentication before continuing.');
        }

        return redirect()
            ->route('security.edit')
            ->with('status', 'Two-factor authentication is required for staff accounts. Set it up to continue.');
    }
}
