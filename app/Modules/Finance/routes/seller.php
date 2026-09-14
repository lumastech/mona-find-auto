<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Finance — Seller routes
|--------------------------------------------------------------------------
|
| Mounted at /seller with the "seller" middleware group.
|
| Read-only, like the earnings screen next to it. A seller downloads the
| statements the platform closed and the commission invoices MonaFind raised
| against them; they cannot generate, amend or re-date any of it.
|
| Ownership is re-checked inside every action against the seller resolved from
| the session, never against an id in the URL.
|
*/

use App\Modules\Finance\Http\Controllers\Seller\StatementController;
use Illuminate\Support\Facades\Route;

Route::get('statements', [StatementController::class, 'index'])->name('statements.index');
Route::get('statements/invoices/{invoice}', [StatementController::class, 'invoice'])->name('statements.invoice');
Route::get('statements/{statement}/{format}', [StatementController::class, 'download'])->name('statements.download');
