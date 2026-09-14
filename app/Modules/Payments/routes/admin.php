<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments — Admin routes
|--------------------------------------------------------------------------
|
| Mounted at /admin with the "admin" middleware group; every route is
| additionally gated by PayoutBatchPolicy, RefundPolicy or the
| view-reconciliation ability, all of which are Finance and platform admin
| only. A moderator working the dispute queue has no business here.
|
*/

use App\Modules\Payments\Http\Controllers\Admin\PayoutBatchController;
use App\Modules\Payments\Http\Controllers\Admin\ReconciliationController;
use App\Modules\Payments\Http\Controllers\Admin\RefundQueueController;
use App\Modules\Payments\Http\Controllers\Admin\SellerPaymentModeController;
use Illuminate\Support\Facades\Route;

/* Payout runs, under dual control. */
Route::get('payouts', [PayoutBatchController::class, 'index'])->name('payouts.index');
Route::post('payouts', [PayoutBatchController::class, 'store'])->name('payouts.store');
Route::get('payouts/{batch}', [PayoutBatchController::class, 'show'])->name('payouts.show');
Route::post('payouts/{batch}/approve', [PayoutBatchController::class, 'approve'])->name('payouts.approve');
Route::post('payouts/{batch}/cancel', [PayoutBatchController::class, 'cancel'])->name('payouts.cancel');

/* Refunds code could not finish on its own. */
Route::get('refunds', [RefundQueueController::class, 'index'])->name('refunds.index');
Route::post('refunds/{refund}/complete', [RefundQueueController::class, 'complete'])->name('refunds.complete');

/* Escrow or direct, per seller, with the recommendation on screen. */
Route::get('sellers/{seller}/payment-mode', [SellerPaymentModeController::class, 'edit'])
    ->name('sellers.payment-mode.edit');
Route::put('sellers/{seller}/payment-mode', [SellerPaymentModeController::class, 'update'])
    ->name('sellers.payment-mode.update');

/* Nightly reconciliation and its exceptions. */
Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
Route::post('reconciliation', [ReconciliationController::class, 'store'])->name('reconciliation.store');
Route::get('reconciliation/{run}', [ReconciliationController::class, 'show'])->name('reconciliation.show');
Route::post('reconciliation/exceptions/{exception}/resolve', [ReconciliationController::class, 'resolve'])
    ->name('reconciliation.exceptions.resolve');
