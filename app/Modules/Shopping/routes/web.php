<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shopping — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group.
|
| All of it is behind auth, and that is the point rather than an oversight. A
| guest pressing the heart or "Add to cart" is sent to log in and comes back
| to where they were, because Laravel's intended-url redirect is what makes a
| guarded route the right shape for these buttons. Hiding them from guests
| instead would mean a buyer discovering at checkout that the platform wanted
| an account all along.
|
*/

use App\Modules\Shopping\Http\Controllers\Storefront\CartController;
use App\Modules\Shopping\Http\Controllers\Storefront\QuotationController;
use App\Modules\Shopping\Http\Controllers\Storefront\SellerEnquiryController;
use App\Modules\Shopping\Http\Controllers\Storefront\WishlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {

    /* Wishlist. Bound by slug, like every other listing route on the storefront. */
    Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist');
    Route::post('wishlist/{product}', [WishlistController::class, 'store'])->name('wishlist.store');
    Route::delete('wishlist/{product}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');
    Route::post('wishlist/{product}/move-to-cart', [WishlistController::class, 'moveToCart'])
        ->name('wishlist.move-to-cart');

    /* Cart. */
    Route::get('cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('cart', [CartController::class, 'store'])->name('cart.store');
    Route::patch('cart/{item}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('cart/{item}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

    /* Requests for quotation, the buyer's side. */
    Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
    Route::post('quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])
        ->name('quotations.accept');

    /* Contact seller — the message thread stub. */
    Route::post('sellers/{seller}/enquiries', [SellerEnquiryController::class, 'store'])
        ->name('sellers.enquiries.store');
});
