<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mechanics — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group.
|
| The directory and the profile pages are open to everyone and server-rendered
| so search engines index them; the contact-blur rule is what a guest gets
| instead of a phone number. The application form is behind auth because a
| mechanic profile attaches to an account that already exists.
|
*/

use App\Modules\Mechanics\Http\Controllers\Storefront\EndorsementRequestController;
use App\Modules\Mechanics\Http\Controllers\Storefront\MechanicApplicationController;
use App\Modules\Mechanics\Http\Controllers\Storefront\MechanicDirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('mechanics', [MechanicDirectoryController::class, 'index'])->name('mechanics.index');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('mechanics/apply', [MechanicApplicationController::class, 'show'])->name('mechanics.apply');
    Route::post('mechanics/apply', [MechanicApplicationController::class, 'store'])->name('mechanics.apply.store');
    Route::post('mechanics/apply/submit', [MechanicApplicationController::class, 'submit'])->name('mechanics.apply.submit');

    Route::post('mechanics/endorsements', [EndorsementRequestController::class, 'store'])
        ->name('mechanics.endorsements.store');
});

/*
 * Last, so "mechanics/apply" is never swallowed by the slug.
 */
Route::get('mechanics/{mechanic}', [MechanicDirectoryController::class, 'show'])->name('mechanics.show');
