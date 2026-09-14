<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group, behind Sanctum.
|
| The mobile app's payment path. Mobile money is server-initiated here — a
| USSD push to the handset — because there is no browser to open the inline
| widget in; `intent` still hands back the widget config for the card path,
| which the app opens in a web view.
|
*/

use App\Modules\Payments\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

    Route::post('order-groups/{group}/payment-intent', [PaymentController::class, 'intent'])
        ->middleware('throttle:payments')
        ->name('payments.intent');

    Route::post('order-groups/{group}/pay/mobile-money', [PaymentController::class, 'mobileMoney'])
        ->middleware('throttle:payments')
        ->name('payments.mobile-money');

    Route::get('order-groups/{group}/payment-status', [PaymentController::class, 'status'])
        ->middleware('throttle:payments')
        ->name('payments.status');
});
