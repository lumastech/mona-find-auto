<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group.
|
| Everything except the webhook is behind auth, and every one of those routes
| additionally checks that the group belongs to the buyer asking — a payment
| URL is guessable in principle and must be useless in practice.
|
| The webhook is the exception and has to be: Lenco has no session and no CSRF
| token. It is authenticated by HMAC signature instead, and is excluded from
| CSRF in bootstrap/app.php.
|
*/

use App\Modules\Payments\Http\Controllers\Storefront\PaymentController;
use App\Modules\Payments\Http\Controllers\Webhooks\LencoWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {

    /* The pay screen, which opens the Lenco widget. */
    Route::get('pay/{group}', [PaymentController::class, 'show'])->name('payments.show');

    /*
     * Where the widget's onSuccess posts. Takes no input beyond the group —
     * the server decides what happened by asking Lenco.
     */
    Route::post('pay/{group}/verify', [PaymentController::class, 'verify'])
        ->middleware('throttle:payments')
        ->name('payments.verify');

    /* Pending, success and failure are one page that polls itself. */
    Route::get('pay/{group}/status', [PaymentController::class, 'status'])->name('payments.status');
    Route::get('pay/{group}/poll', [PaymentController::class, 'poll'])
        ->middleware('throttle:payments')
        ->name('payments.poll');
});

/*
 * Signature-authenticated, rate-limited, no session. The limiter is generous
 * enough never to throttle Lenco's real traffic and tight enough that an
 * unsigned flood cannot cost us a database write per request.
 */
Route::post('webhooks/lenco', LencoWebhookController::class)
    ->middleware('throttle:webhooks')
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.lenco');
