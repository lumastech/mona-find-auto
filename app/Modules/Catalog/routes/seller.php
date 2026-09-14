<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catalog — Seller portal routes
|--------------------------------------------------------------------------
|
| Mounted at /seller behind the `seller` middleware group. A seller writes
| their listings here, attaches media to them, and sends them for review.
|
| Nothing here sets the inspection badge or publishes anything directly: both
| are MonaFind's, and both live under /admin.
|
*/

use App\Modules\Catalog\Http\Controllers\Seller\ListingMediaController;
use App\Modules\Catalog\Http\Controllers\Seller\ListingSubmissionController;
use App\Modules\Catalog\Http\Controllers\Seller\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('listings', [ProductController::class, 'index'])->name('listings.index');
Route::get('listings/create', [ProductController::class, 'create'])->name('listings.create');
Route::post('listings', [ProductController::class, 'store'])->name('listings.store');
Route::get('listings/{product}/edit', [ProductController::class, 'edit'])->name('listings.edit');
Route::put('listings/{product}', [ProductController::class, 'update'])->name('listings.update');
Route::delete('listings/{product}', [ProductController::class, 'destroy'])->name('listings.destroy');

/* In and out of the moderation queue, and on and off the storefront. */
Route::post('listings/{product}/submit', [ListingSubmissionController::class, 'submit'])->name('listings.submit');
Route::post('listings/{product}/withdraw', [ListingSubmissionController::class, 'withdraw'])->name('listings.withdraw');
Route::post('listings/{product}/unpublish', [ListingSubmissionController::class, 'unpublish'])->name('listings.unpublish');
Route::post('listings/{product}/republish', [ListingSubmissionController::class, 'republish'])->name('listings.republish');

Route::post('listings/{product}/photos', [ListingMediaController::class, 'storePhotos'])->name('listings.photos.store');
Route::put('listings/{product}/photos/order', [ListingMediaController::class, 'reorderPhotos'])->name('listings.photos.order');
Route::delete('listings/{product}/photos/{media}', [ListingMediaController::class, 'destroyPhoto'])->name('listings.photos.destroy');
Route::post('listings/{product}/video', [ListingMediaController::class, 'storeVideo'])->name('listings.video.store');
Route::delete('listings/{product}/video', [ListingMediaController::class, 'destroyVideo'])->name('listings.video.destroy');
