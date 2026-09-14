<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inventory — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group.
|
| Behind auth, unlike the rest of the storefront: there is nowhere to send a
| back-in-stock notification to somebody the platform cannot identify. A guest
| sees the button on the listing page and is sent to log in.
|
*/

use App\Modules\Inventory\Http\Controllers\Storefront\BackInStockController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::post('stock-alerts/{variant}', [BackInStockController::class, 'store'])->name('stock-alerts.store');
    Route::delete('stock-alerts/{variant}', [BackInStockController::class, 'destroy'])->name('stock-alerts.destroy');
});
