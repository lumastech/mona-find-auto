<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Skips server-side rendering for an area.
 *
 * SSR exists here for search engines, so it earns its latency only on the
 * public storefront. The seller portal and staff console sit behind a login
 * and are never indexed, so they render on the client.
 */
class DisableSsr
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::disableSsr();

        return $next($request);
    }
}
