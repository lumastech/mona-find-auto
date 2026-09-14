<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group and the "api.v1."
| route-name prefix. Sanctum tokens authenticate the future mobile app.
|
| Every buyer and seller capability is mirrored here; feature endpoints
| belong in the owning module's routes/api.php, and only the platform-level
| endpoints below live in this file.
|
*/

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\PlatformController;
use Illuminate\Support\Facades\Route;

Route::get('platform', PlatformController::class)->name('platform');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('account', AccountController::class)->name('account');
});
