<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sellers — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group.
|
| Two very different things live here. The public seller page is open to
| everyone — it is server-rendered so search engines index it, and the
| contact-blur rule is what a guest gets instead of a phone number. The
| sign-up wizard is behind auth because the person filling it in is a buyer
| who does not have the seller role yet; submitting is what grants it.
|
*/

use App\Modules\Sellers\Http\Controllers\Seller\DocumentController;
use App\Modules\Sellers\Http\Controllers\Seller\PayoutAccountController;
use App\Modules\Sellers\Http\Controllers\Storefront\SellerProfileController;
use App\Modules\Sellers\Http\Controllers\Storefront\SellerRegistrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('sell/register', [SellerRegistrationController::class, 'show'])->name('sellers.register');
    Route::get('sell/register/{step}', [SellerRegistrationController::class, 'show'])->name('sellers.register.step');
    Route::post('sell/register/{step}', [SellerRegistrationController::class, 'store'])->name('sellers.register.store');
    Route::post('sell/register-submit', [SellerRegistrationController::class, 'submit'])->name('sellers.register.submit');

    /*
     * Steps four and five attach rows to the draft seller. The portal routes
     * that do the same job sit behind the seller-role gate, which an applicant
     * does not pass until they submit — so the wizard gets its own doors onto
     * the same controllers. Ownership is still checked by the `manage` policy.
     */
    Route::post('sell/register/payout/accounts', [PayoutAccountController::class, 'store'])->name('sellers.register.payout-accounts.store');
    Route::delete('sell/register/payout/accounts/{payoutAccount}', [PayoutAccountController::class, 'destroy'])->name('sellers.register.payout-accounts.destroy');
    Route::post('sell/register/documents/files', [DocumentController::class, 'store'])->name('sellers.register.documents.store');
    Route::delete('sell/register/documents/files/{media}', [DocumentController::class, 'destroy'])->name('sellers.register.documents.destroy');
});

/*
 * Last, so "sell" and "sell/register" are never swallowed by the slug.
 */
Route::get('sellers/{seller}', [SellerProfileController::class, 'show'])->name('sellers.show');
