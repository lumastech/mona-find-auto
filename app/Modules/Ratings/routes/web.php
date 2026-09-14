<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ratings — Storefront routes
|--------------------------------------------------------------------------
|
| Guest and buyer facing routes, mounted at /.
|
| Both are writes and both need an account, so the whole file sits behind
| auth. Reading reviews needs no route at all: they are props on the listing
| and seller pages that already exist.
|
| Leaving a review hangs off the order, because the order is what the buyer is
| looking at. Endorsement ratings will hang off their own route in the
| Mechanics module and call the same service.
|
*/

use App\Modules\Ratings\Http\Controllers\Storefront\RatingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {

    Route::post('orders/{order}/ratings', [RatingController::class, 'store'])
        ->name('orders.ratings.store');

    Route::post('ratings/{rating}/report', [RatingController::class, 'report'])
        ->name('ratings.report');
});
