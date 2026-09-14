<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ledger — Admin routes
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the "admin" middleware group. Grouped under
| /finance because that is what staff call it, and gated further by policy:
| finance and platform administrators read, platform administrators reprice,
| and posting a manual adjustment takes two of them.
|
| There is no route that edits a journal entry, because there is no such
| thing. Corrections are adjustments, and adjustments are new entries.
|
*/

use App\Modules\Ledger\Http\Controllers\Admin\LedgerAdjustmentController;
use App\Modules\Ledger\Http\Controllers\Admin\LedgerBrowserController;
use App\Modules\Ledger\Http\Controllers\Admin\MonetisationPolicyController;
use App\Modules\Ledger\Http\Controllers\Admin\SellerPolicyAssignmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->name('finance.')->group(function (): void {

    /* The ledger itself: read-only, always. */
    Route::get('ledger', [LedgerBrowserController::class, 'index'])->name('ledger.index');
    Route::get('ledger/{entry}', [LedgerBrowserController::class, 'show'])->name('ledger.show');

    /* Monetisation policies and who is on them. */
    Route::get('policies', [MonetisationPolicyController::class, 'index'])->name('policies.index');
    Route::post('policies', [MonetisationPolicyController::class, 'store'])->name('policies.store');
    Route::put('policies/{policy}', [MonetisationPolicyController::class, 'update'])->name('policies.update');
    Route::post('policies/{policy}/default', [MonetisationPolicyController::class, 'makeDefault'])->name('policies.default');
    Route::post('policies/{policy}/active', [MonetisationPolicyController::class, 'toggleActive'])->name('policies.active');

    Route::get('policies/sellers/assignments', [SellerPolicyAssignmentController::class, 'index'])->name('policies.sellers');
    Route::put('policies/sellers/{seller}', [SellerPolicyAssignmentController::class, 'update'])->name('policies.assign');

    /* Manual adjustments: drafted by finance, decided by an administrator. */
    Route::get('adjustments', [LedgerAdjustmentController::class, 'index'])->name('adjustments.index');
    Route::post('adjustments', [LedgerAdjustmentController::class, 'store'])->name('adjustments.store');
    Route::post('adjustments/{adjustment}/approve', [LedgerAdjustmentController::class, 'approve'])->name('adjustments.approve');
    Route::post('adjustments/{adjustment}/reject', [LedgerAdjustmentController::class, 'reject'])->name('adjustments.reject');

});
