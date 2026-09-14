<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Privacy — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group, behind Sanctum.
|
| A person's data-protection rights cannot depend on which client they are
| holding, so everything the web settings screen offers is here too and both
| go through the same services.
|
| There is no PDF endpoint: an app wants the structure, which `export`
| returns, and can render it itself.
|
*/

use App\Modules\Privacy\Http\Controllers\Api\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('privacy')->name('privacy.')->group(function (): void {

    Route::get('/', [PrivacyController::class, 'show'])->name('show');

    Route::put('consents', [PrivacyController::class, 'updateConsent'])->name('consents.update');

    Route::get('export', [PrivacyController::class, 'export'])
        ->middleware('throttle:6,1')
        ->name('export');

    Route::post('erasure', [PrivacyController::class, 'requestErasure'])
        ->middleware('throttle:6,1')
        ->name('erasure.store');
    Route::delete('erasure/{erasureRequest}', [PrivacyController::class, 'cancelErasure'])
        ->name('erasure.cancel');
});
