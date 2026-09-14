<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mechanics — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| Public, like the storefront: guests may browse the directory. Contact
| details follow the same blur rule as the web page, applied by the one
| resource both surfaces answer through.
|
| Bound by id rather than slug — the mobile app holds ids.
|
*/

use App\Modules\Mechanics\Http\Controllers\Api\MechanicController;
use Illuminate\Support\Facades\Route;

Route::get('mechanics', [MechanicController::class, 'index'])->name('mechanics.index');
Route::get('mechanics/specialities', [MechanicController::class, 'specialities'])->name('mechanics.specialities');

/* Last, so "specialities" is not read as an id. */
Route::get('mechanics/{mechanic:id}', [MechanicController::class, 'show'])->name('mechanics.show');
