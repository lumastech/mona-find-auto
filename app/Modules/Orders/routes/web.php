<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Orders — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group.
|
| All of it is behind auth. There is no guest checkout on MonaFind and there
| cannot be: an order has a buyer who confirms receipt, raises disputes and
| receives refunds, and every one of those needs an account to come back to.
|
| Orders are bound by number rather than id — MF-7QK4ZP2A is what the buyer
| reads off their receipt and out to a seller over a counter.
|
*/

use App\Modules\Orders\Http\Controllers\Storefront\CheckoutController;
use App\Modules\Orders\Http\Controllers\Storefront\DisputeController;
use App\Modules\Orders\Http\Controllers\Storefront\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {

    /*
     * Checkout. Placing an order takes no money; payment is its own step.
     *
     * The POST is throttled: it reserves stock, snapshots a monetisation
     * policy and writes an order group, which makes it the most expensive
     * thing a buyer can ask the platform for.
     */
    Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:checkout')
        ->name('checkout.store');

    /* The buyer's own orders. */
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('orders/{order}/receipt', [OrderController::class, 'receipt'])->name('orders.receipt');

    Route::post('orders/{order}/confirm-receipt', [OrderController::class, 'confirmReceipt'])
        ->name('orders.confirm-receipt');

    Route::post('orders/{order}/disputes', [DisputeController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('orders.disputes.store');
});
