<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shopping — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| Every buyer and seller shopping capability the web areas have, mirrored for
| the mobile app. All of it needs a token: a cart, a wishlist and a quote
| request all belong to somebody in particular.
|
| Bound by id rather than slug throughout — the app holds ids.
|
*/

use App\Modules\Shopping\Http\Controllers\Api\CartController;
use App\Modules\Shopping\Http\Controllers\Api\QuotationController;
use App\Modules\Shopping\Http\Controllers\Api\SellerEnquiryController;
use App\Modules\Shopping\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {

    Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('wishlist/{product:id}', [WishlistController::class, 'store'])->name('wishlist.store');
    Route::delete('wishlist/{product:id}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');
    Route::post('wishlist/{product:id}/move-to-cart', [WishlistController::class, 'moveToCart'])
        ->name('wishlist.move-to-cart');

    Route::get('cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('cart', [CartController::class, 'store'])->name('cart.store');
    Route::patch('cart/{item}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('cart/{item}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

    Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
    Route::post('quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::post('quotations/{quotation}/respond', [QuotationController::class, 'respond'])->name('quotations.respond');
    Route::post('quotations/{quotation}/decline', [QuotationController::class, 'decline'])->name('quotations.decline');
    Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])->name('quotations.accept');

    /* "Contact seller" — the same channel the web button writes to. */
    Route::post('sellers/{seller:id}/enquiries', [SellerEnquiryController::class, 'store'])
        ->name('sellers.enquiries.store');
});
