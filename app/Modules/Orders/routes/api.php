<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Orders — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| The buyer's side of orders, mirrored for the mobile app. It calls the same
| services the web area does, so there is one rulebook rather than two.
|
| Bound by order number here as well as on the web: the app shows the buyer
| the same reference their receipt carries.
|
*/

use App\Modules\Orders\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {

    Route::get('checkout', [OrderController::class, 'checkout'])->name('checkout.show');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware('throttle:checkout')
        ->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('orders/{order}/receipt', [OrderController::class, 'receipt'])->name('orders.receipt');

    Route::post('orders/{order}/confirm-receipt', [OrderController::class, 'confirmReceipt'])
        ->name('orders.confirm-receipt');
    Route::post('orders/{order}/disputes', [OrderController::class, 'dispute'])
        ->middleware('throttle:uploads')
        ->name('orders.disputes.store');
});
