<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The browser sessions and API tokens an account currently has open.
 *
 * Sessions are read straight from the session table rather than through the
 * session driver: the point is to see *other* devices, which the current
 * request's driver knows nothing about. When the application is not using the
 * database driver there is nothing to list, and the registry says so by
 * returning an empty collection rather than failing.
 */
class SessionRegistry
{
    /**
     * Every browser session for an account, most recently active first.
     *
     * @return Collection<int, array{id: string, ip_address: string|null, user_agent: string|null, device: string, platform: string|null, browser: string|null, last_active: string, last_active_at: Carbon, is_current: bool}>
     */
    public function browserSessions(User $user, ?string $currentSessionId = null): Collection
    {
        if (! $this->usesDatabaseSessions()) {
            return collect();
        }

        $currentSessionId ??= session()->getId();

        return collect(
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->orderByDesc('last_activity')
                ->get()
        )->map(function (object $session) use ($currentSessionId): array {
            $lastActiveAt = Carbon::createFromTimestamp($session->last_activity);
            $agent = $this->describe(is_string($session->user_agent) ? $session->user_agent : '');

            return [
                'id' => (string) $session->id,
                'ip_address' => is_string($session->ip_address) ? $session->ip_address : null,
                'user_agent' => is_string($session->user_agent) ? $session->user_agent : null,
                'device' => $agent['device'],
                'platform' => $agent['platform'],
                'browser' => $agent['browser'],
                'last_active' => $lastActiveAt->diffForHumans(),
                'last_active_at' => $lastActiveAt,
                'is_current' => (string) $session->id === $currentSessionId,
            ];
        })->values();
    }

    /**
     * End one browser session. Returns false when the session does not belong
     * to this account, so a guessed id cannot log somebody else out.
     */
    public function revokeBrowserSession(User $user, string $sessionId): bool
    {
        if (! $this->usesDatabaseSessions()) {
            return false;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', $sessionId)
            ->delete() > 0;
    }

    /**
     * End every browser session for an account, optionally sparing the one
     * making the request.
     */
    public function revokeAllBrowserSessions(User $user, ?string $exceptSessionId = null): int
    {
        if (! $this->usesDatabaseSessions()) {
            return 0;
        }

        $query = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier());

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query->delete();
    }

    /**
     * Cut every way in EXCEPT the one being used right now.
     *
     * What a password change should do. Ending the caller's own session too
     * would sign somebody out of the app at the moment they successfully
     * changed their password, which reads as a failure; every other session,
     * token and the remember cookie do go, which is the point of the
     * exercise.
     *
     * `$currentToken` is the Sanctum token that authenticated the request —
     * `$request->user()->currentAccessToken()` — or null on the web, where
     * the session id spares the browser instead.
     */
    public function revokeOthers(User $user, ?string $exceptSessionId = null, mixed $currentToken = null): void
    {
        $this->revokeAllBrowserSessions($user, $exceptSessionId);

        $tokens = $user->tokens();

        if ($currentToken !== null && isset($currentToken->id)) {
            $tokens->whereKeyNot($currentToken->id);
        }

        $tokens->delete();

        $user->forceFill(['remember_token' => null])->saveQuietly();
    }

    /**
     * Cut every way an account can reach the platform: browser sessions,
     * API tokens and the remember-me cookie.
     *
     * Used when staff suspend or close an account — the block has to take
     * effect now, not at the next login.
     */
    public function revokeEverything(User $user): void
    {
        $this->revokeAllBrowserSessions($user);

        $user->tokens()->delete();

        $user->forceFill(['remember_token' => null])->saveQuietly();
    }

    /**
     * Pull the readable bits out of a user-agent string.
     *
     * Deliberately shallow: this exists so somebody can recognise their own
     * phone in a list, not to fingerprint a visitor. Anything unrecognised is
     * shown as an unknown device rather than as a raw UA string.
     *
     * @return array{device: string, platform: string|null, browser: string|null}
     */
    private function describe(string $userAgent): array
    {
        $platform = $this->firstMatch($userAgent, [
            'Android' => 'Android',
            'iPhone' => 'iOS',
            'iPad' => 'iPadOS',
            'Windows' => 'Windows',
            'Macintosh' => 'macOS',
            'CrOS' => 'ChromeOS',
            'Linux' => 'Linux',
        ]);

        /* Order matters: Edge and Opera both claim to be Chrome, Chrome claims to be Safari. */
        $browser = $this->firstMatch($userAgent, [
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
        ]);

        if ($platform === null && $browser === null) {
            return ['device' => 'Unknown device', 'platform' => null, 'browser' => null];
        }

        return [
            'device' => ($browser ?? 'Unknown browser').' on '.($platform ?? 'unknown platform'),
            'platform' => $platform,
            'browser' => $browser,
        ];
    }

    /**
     * The label for the first needle present in the haystack.
     *
     * @param  array<string, string>  $needles
     */
    private function firstMatch(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle => $label) {
            if (Str::contains($haystack, $needle)) {
                return $label;
            }
        }

        return null;
    }

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }
}
