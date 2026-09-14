<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ratings — Seller portal routes
|--------------------------------------------------------------------------
|
| Mounted at /seller behind the "seller" middleware group.
|
| The reviews page is where a seller reads what buyers said, posts their one
| reply, and sees the trust score those reviews feed.
|
*/

use App\Modules\Ratings\Http\Controllers\Seller\RatingController;
use Illuminate\Support\Facades\Route;

Route::get('ratings', [RatingController::class, 'index'])->name('ratings.index');

Route::post('ratings/{rating}/reply', [RatingController::class, 'reply'])->name('ratings.reply');

/* The shop's private rating of a buyer, left from the order it belongs to. */
Route::post('orders/{order}/ratings', [RatingController::class, 'store'])->name('orders.ratings.store');
