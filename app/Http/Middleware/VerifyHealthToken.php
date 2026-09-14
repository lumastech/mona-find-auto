<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the deep health endpoint behind a shared token.
 *
 * The report names every dependency the platform has and which of them are
 * currently broken. That is useful to an operator and just as useful to
 * somebody deciding when to attack, so it is not served to the open internet
 * unless a deployment has decided it should be.
 *
 * ## Open when no token is configured
 *
 * A local install has no token and should have a working health endpoint. The
 * launch checklist carries the line that sets one for production, and the
 * report itself is the thing that would reveal the omission.
 *
 * ## Compared in constant time
 *
 * `hash_equals`, not `===`. A token compared with an early-exit string
 * comparison leaks its length and its prefix to anyone patient enough to
 * measure, and this one is a bearer credential.
 */
class VerifyHealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('security.health_token');

        if (! is_string($expected) || $expected === '') {
            return $next($request);
        }

        $presented = $request->bearerToken() ?? $request->header('X-Health-Token') ?? '';

        if (! hash_equals($expected, $presented)) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
