<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inventory — Seller portal routes
|--------------------------------------------------------------------------
|
| Mounted at /seller behind the `seller` middleware group. A seller confirms
| their stock here, corrects quantities, and uploads a stock file.
|
| The confirmation route is declared before the import routes so that
| "stock/confirm" is never read as a batch id.
|
*/

use App\Modules\Inventory\Http\Controllers\Seller\StockConfirmationController;
use App\Modules\Inventory\Http\Controllers\Seller\StockController;
use App\Modules\Inventory\Http\Controllers\Seller\StockImportController;
use Illuminate\Support\Facades\Route;

Route::get('stock', [StockController::class, 'index'])->name('stock.index');
Route::put('stock/{product}', [StockController::class, 'update'])->name('stock.update');

/* The one-tap button the whole freshness scheme rests on. */
Route::post('stock/confirm', [StockConfirmationController::class, 'store'])->name('stock.confirm');
Route::post('stock/{product}/confirm', [StockConfirmationController::class, 'storeForProduct'])->name('stock.confirm.product');

Route::get('stock/template/download', [StockImportController::class, 'template'])->name('stock.template');
Route::post('stock/imports', [StockImportController::class, 'store'])->name('stock.imports.store');
Route::get('stock/imports/{batch}', [StockImportController::class, 'show'])->name('stock.imports.show');
Route::post('stock/imports/{batch}/apply', [StockImportController::class, 'apply'])->name('stock.imports.apply');
Route::delete('stock/imports/{batch}', [StockImportController::class, 'destroy'])->name('stock.imports.destroy');
