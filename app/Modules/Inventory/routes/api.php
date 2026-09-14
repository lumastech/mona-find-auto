<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inventory — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| Every seller and buyer stock capability the web areas have, mirrored for the
| mobile app. All of it needs a token: stock levels are a shop's own business,
| and a back-in-stock alert needs somebody to send it to.
|
*/

use App\Modules\Inventory\Http\Controllers\Api\BackInStockController;
use App\Modules\Inventory\Http\Controllers\Api\StockConfirmationController;
use App\Modules\Inventory\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('seller/stock', [StockController::class, 'index'])->name('seller.stock.index');
    Route::post('seller/stock/confirm', [StockConfirmationController::class, 'store'])->name('seller.stock.confirm');
    Route::post('seller/stock/{product:id}/confirm', [StockConfirmationController::class, 'storeForProduct'])
        ->name('seller.stock.confirm.product');

    Route::post('stock-alerts/{variant:id}', [BackInStockController::class, 'store'])->name('stock-alerts.store');
    Route::delete('stock-alerts/{variant:id}', [BackInStockController::class, 'destroy'])->name('stock-alerts.destroy');
});
