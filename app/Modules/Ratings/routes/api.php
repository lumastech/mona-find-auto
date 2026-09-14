<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ratings — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| The list is open, because reviews are what a buyer reads before deciding and
| the app should not have to sign somebody in to show them. It can only ever
| return the three public directions — see the controller.
|
| Writing needs a token, and goes through the same service as the web form.
|
*/

use App\Modules\Ratings\Http\Controllers\Api\RatingController;
use Illuminate\Support\Facades\Route;

Route::get('ratings', [RatingController::class, 'index'])->name('ratings.index');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('orders/{order}/ratings/prompts', [RatingController::class, 'prompts'])
        ->name('orders.ratings.prompts');
    Route::post('orders/{order}/ratings', [RatingController::class, 'store'])
        ->name('orders.ratings.store');

    /* Objecting to a review somebody left about you. */
    Route::post('ratings/{rating}/report', [RatingController::class, 'report'])
        ->name('ratings.report');
});
