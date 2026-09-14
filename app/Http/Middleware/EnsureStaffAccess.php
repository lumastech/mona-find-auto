<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Roles\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /admin console to MonaFind staff.
 */
class EnsureStaffAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null || ! $user->hasAnyRole(Role::staffConsole()), Response::HTTP_FORBIDDEN, 'This area is for MonaFind staff.');

        return $next($request);
    }
}
