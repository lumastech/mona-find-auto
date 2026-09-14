<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Search — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group, open to guests. A buyer finds
| out whether MonaFind has their part before being asked who they are; the
| seller's phone number on the listing page is where that changes.
|
*/

use App\Modules\Search\Http\Controllers\Storefront\SearchController;
use Illuminate\Support\Facades\Route;

/*
 * Throttled: the storefront search is the busiest unauthenticated surface
 * on the platform and the first thing a scraper reaches for.
 */
Route::get('search', SearchController::class)
    ->middleware('throttle:search')
    ->name('search');
