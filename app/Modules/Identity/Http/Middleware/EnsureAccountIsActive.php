<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Services\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a suspended or closed account from doing anything, anywhere.
 *
 * Staff tear down sessions at the moment they suspend an account, but a
 * request can already be in flight and a remembered cookie can rebuild a
 * session, so the block is re-checked here on every request. Guests and
 * accounts still pending phone verification pass through — a guest has
 * nothing to block, and a pending account is limited by the verification
 * screen rather than by a hard block.
 */
class EnsureAccountIsActive
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->status->forcesLogout()) {
            return $next($request);
        }

        $message = $user->status->blockedMessage($user->status_reason);

        $this->sessions->revokeEverything($user);

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, $message);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message);
    }
}
