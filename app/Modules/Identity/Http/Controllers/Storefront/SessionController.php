<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The devices signed in to an account, and how to sign them out.
 *
 * Shared phones and internet cafés are common, so "log out everywhere else"
 * is a security control people here actually reach for.
 */
class SessionController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly SessionRegistry $sessions) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);

        return Inertia::render('settings/Sessions', [
            'sessions' => $this->sessions
                ->browserSessions($user, $request->session()->getId())
                ->map(fn (array $session): array => [
                    ...$session,
                    'last_active_at' => $session['last_active_at']->toIso8601String(),
                ])
                ->all(),
            'apiTokens' => $user->tokens()
                ->latest('last_used_at')
                ->get()
                ->map(fn ($token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used' => $token->last_used_at?->diffForHumans(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ])
                ->all(),
            'tracksSessions' => config('session.driver') === 'database',
        ]);
    }

    /**
     * Sign one other device out.
     */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        $user = $this->currentUser($request);

        $revoked = $this->sessions->revokeBrowserSession($user, $session);

        audit($user, 'user.session_revoked', $user, null, ['session' => $session]);

        Inertia::flash('toast', [
            'type' => $revoked ? 'success' : 'error',
            'message' => $revoked ? __('That device was signed out.') : __('That session is no longer active.'),
        ]);

        return to_route('sessions.index');
    }

    /**
     * Sign every other device out, and drop the API tokens with them —
     * somebody clearing their sessions after losing a phone means all of it.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);

        $this->sessions->revokeAllBrowserSessions($user, $request->session()->getId());
        $user->tokens()->delete();

        audit($user, 'user.sessions_revoked', $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('All other devices were signed out.')]);

        return to_route('sessions.index');
    }
}
