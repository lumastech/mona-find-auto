<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Privacy — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group.
|
| All of it is behind auth, because every one of these actions is about a
| specific person's data and there is no version of it a guest can ask for.
|
| The two downloads are throttled. They walk every table that holds personal
| data for the account and render a PDF at the end of it, which is the most
| expensive thing an ordinary buyer can ask for on a GET — and, unlike search,
| there is no legitimate reason to ask twice a minute.
|
*/

use App\Modules\Privacy\Http\Controllers\Storefront\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {

    Route::get('settings/privacy', [PrivacyController::class, 'index'])->name('privacy.index');

    Route::put('settings/privacy/consents', [PrivacyController::class, 'updateConsent'])
        ->name('privacy.consents.update');

    Route::get('settings/privacy/export.json', [PrivacyController::class, 'downloadJson'])
        ->middleware('throttle:6,1')
        ->name('privacy.export.json');
    Route::get('settings/privacy/export.pdf', [PrivacyController::class, 'downloadPdf'])
        ->middleware('throttle:6,1')
        ->name('privacy.export.pdf');

    /*
     * Deletion. The POST re-checks the password (see RequestErasureRequest);
     * the DELETE is how a person calls it off inside the grace period.
     */
    Route::post('settings/privacy/erasure', [PrivacyController::class, 'requestErasure'])
        ->middleware('throttle:6,1')
        ->name('privacy.erasure.store');
    Route::delete('settings/privacy/erasure/{erasureRequest}', [PrivacyController::class, 'cancelErasure'])
        ->name('privacy.erasure.cancel');
});
