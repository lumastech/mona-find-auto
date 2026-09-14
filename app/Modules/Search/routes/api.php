<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Search — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| The same parameters, ranking, tiers and facet counts as the storefront page:
| both go through App\Modules\Search\Http\Requests\SearchRequest and
| App\Modules\Search\Services\ProductSearch, so the mobile app can reproduce
| any result a buyer reached in a browser.
|
*/

use App\Modules\Search\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('search', SearchController::class)
    ->middleware('throttle:search')
    ->name('search');
