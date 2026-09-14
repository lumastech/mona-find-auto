<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Finance — Admin routes
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the "admin" middleware group and grouped under
| /finance, alongside Ledger's own screens, because that is one place in the
| console as far as staff are concerned.
|
| Everything here is gated by `finance` at minimum. Two things need more: the
| VAT schedule and rebuilding a closed statement are platform-administrator
| acts, because one changes a tax figure and the other supersedes a document a
| seller may already be holding.
|
| There is nothing here that edits a ledger entry, a statement figure or an
| invoice. Those are all closed records; this module reads them and renders
| them, and corrections are made where they belong — as new ledger entries.
|
*/

use App\Modules\Finance\Http\Controllers\Admin\FinanceDashboardController;
use App\Modules\Finance\Http\Controllers\Admin\FinanceExportController;
use App\Modules\Finance\Http\Controllers\Admin\StatementController;
use App\Modules\Finance\Http\Controllers\Admin\VatRateController;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->name('finance.')->group(function (): void {

    /* The platform's own numbers, every one of them a sum of journal lines. */
    Route::get('dashboard', FinanceDashboardController::class)->name('dashboard');

    /* Seller statements and the commission invoices behind them. */
    Route::get('statements', [StatementController::class, 'index'])->name('statements.index');
    Route::post('statements', [StatementController::class, 'store'])->name('statements.store');
    Route::post('statements/sellers/{seller}/regenerate', [StatementController::class, 'regenerate'])
        ->name('statements.regenerate');
    Route::get('statements/{statement}/download/{format}', [StatementController::class, 'download'])
        ->name('statements.download');
    Route::get('statements/invoices/{invoice}', [StatementController::class, 'invoice'])
        ->name('statements.invoice');

    /* The hand-off to the client's accountant. Every download is audited. */
    Route::get('exports', [FinanceExportController::class, 'index'])->name('exports.index');
    Route::get('exports/{export}', [FinanceExportController::class, 'download'])->name('exports.download');

    /* The dated VAT schedule. Reading is finance; changing it is not. */
    Route::get('vat', [VatRateController::class, 'index'])->name('vat.index');
    Route::post('vat', [VatRateController::class, 'store'])->name('vat.store');
    Route::delete('vat/{rate}', [VatRateController::class, 'destroy'])->name('vat.destroy');

});
