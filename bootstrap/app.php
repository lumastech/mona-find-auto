<?php

use App\Http\Api\ApiExceptionRenderer;
use App\Http\Middleware\CacheStorefrontResponses;
use App\Http\Middleware\DisableSsr;
use App\Http\Middleware\EnsureSellerAccess;
use App\Http\Middleware\EnsureStaffAccess;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Modules\Identity\Http\Middleware\EnsureStaffTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /*
             * The three Inertia areas and the versioned JSON API. Each module
             * mounts its own routes into these same groups — see
             * config/modules.php and App\Support\Modules\ModuleServiceProvider.
             */
            Route::middleware('seller')
                ->prefix('seller')
                ->name('seller.')
                ->group(base_path('routes/seller.php'));

            Route::middleware('admin')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('api.v1')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        /*
         * Global, because the transport headers belong on a JSON error as
         * much as on a page, and because the CSP nonce has to be minted
         * before anything renders.
         */
        $middleware->append(SecurityHeaders::class);

        /*
         * PREPENDED, which puts it last on the way back out — middleware
         * unwinds in reverse. It has to run after Inertia's, because Inertia
         * sets its own `Vary: X-Inertia` and would otherwise overwrite the
         * fuller header this adds.
         */
        $middleware->web(prepend: [CacheStorefrontResponses::class]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            /*
             * A suspended or closed account is cut off on its very next
             * request, not at its next login — a live session and a
             * remembered cookie both have to stop working immediately.
             */
            EnsureAccountIsActive::class,
            /*
             * Staff hold roles a stolen password must not inherit, so an
             * account that has not enrolled in two-factor authentication is
             * held at the enrolment screen everywhere, not just in /admin.
             */
            EnsureStaffTwoFactor::class,
        ]);

        /*
         * SSR is for search engines, so it runs on the storefront only; the
         * two authenticated areas opt out of it.
         */
        $middleware->group('seller', [
            'web',
            'auth',
            'verified',
            EnsureSellerAccess::class,
            DisableSsr::class,
        ]);

        $middleware->group('admin', [
            'web',
            'auth',
            'verified',
            EnsureStaffAccess::class,
            DisableSsr::class,
        ]);

        $middleware->group('api.v1', [
            ForceJsonResponse::class,
            'throttle:api',
            SubstituteBindings::class,
            EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(new ApiExceptionRenderer);
    })->create();
