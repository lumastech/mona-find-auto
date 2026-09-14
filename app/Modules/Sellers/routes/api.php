<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sellers — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| Public, like the storefront: guests may browse sellers. Contact details
| follow the same blur rule as the web page, applied by the one resource both
| surfaces answer through.
|
| Bound by id rather than slug — the mobile app holds ids.
|
*/

use App\Modules\Sellers\Http\Controllers\Api\SellerController;
use Illuminate\Support\Facades\Route;

Route::get('sellers', [SellerController::class, 'index'])->name('sellers.index');
Route::get('sellers/{seller:id}', [SellerController::class, 'show'])->name('sellers.show');
