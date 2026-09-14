<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group. Guests may browse everything
| here; only contact details and checkout require an account.
|
| Feature routes belong in the owning module's routes/web.php; only the
| storefront shell and the buyer dashboard live in this file.
|
*/

use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\VerifyHealthToken;
use Illuminate\Support\Facades\Route;

/*
 * The deep health check, beside Laravel's own /up (declared in
 * bootstrap/app.php). /up says PHP is running; this says the instance can
 * actually reach MySQL, Redis, the cache and its workers — see
 * App\Http\Controllers\HealthController for why the difference matters to a
 * load balancer.
 */
Route::get('health', HealthController::class)
    ->middleware([VerifyHealthToken::class, 'throttle:30,1'])
    ->withoutMiddleware(['web'])
    ->name('health');

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'storefront/Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
