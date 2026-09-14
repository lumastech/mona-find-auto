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

use App\Modules\Sellers\Http\Controllers\Storefront\SellerProfileController;
use App\Modules\Sellers\Http\Controllers\Storefront\SellerRegistrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('sell/register', [SellerRegistrationController::class, 'show'])->name('sellers.register');
    Route::get('sell/register/{step}', [SellerRegistrationController::class, 'show'])->name('sellers.register.step');
    Route::post('sell/register/{step}', [SellerRegistrationController::class, 'store'])->name('sellers.register.store');
    Route::post('sell/register-submit', [SellerRegistrationController::class, 'submit'])->name('sellers.register.submit');
});

/*
 * Last, so "sell" and "sell/register" are never swallowed by the slug.
 */
Route::get('sellers/{seller}', [SellerProfileController::class, 'show'])->name('sellers.show');
