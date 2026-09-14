<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Orders — Seller routes
|--------------------------------------------------------------------------
|
| Mounted at /seller behind the "seller" middleware group.
|
| The four fulfilment actions are separate routes but only two state-machine
| calls: which state "mark ready" produces comes from the ORDER's fulfilment
| method, not from the URL. A pickup order has no dispatch route to hit.
|
*/

use App\Modules\Orders\Http\Controllers\Seller\FulfilmentSettingsController;
use App\Modules\Orders\Http\Controllers\Seller\OrderController;
use Illuminate\Support\Facades\Route;

/* The inbox. */
Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
Route::get('orders/{order}/packing-slip', [OrderController::class, 'packingSlip'])
    ->name('orders.packing-slip');

Route::post('orders/{order}/confirm', [OrderController::class, 'confirm'])->name('orders.confirm');
Route::post('orders/{order}/ready', [OrderController::class, 'markReady'])->name('orders.ready');
Route::post('orders/{order}/handed-over', [OrderController::class, 'markHandedOver'])
    ->name('orders.handed-over');
Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

/* What the shop offers buyers at checkout. */
Route::get('fulfilment', [FulfilmentSettingsController::class, 'edit'])->name('fulfilment.edit');
Route::put('fulfilment', [FulfilmentSettingsController::class, 'update'])->name('fulfilment.update');
