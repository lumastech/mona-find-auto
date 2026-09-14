<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Search — Staff console routes
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the `admin` middleware group.
|
| One screen: the searches buyers ran that came back empty. It is the
| reference-data backlog, written by buyers.
|
*/

use App\Modules\Search\Http\Controllers\Admin\SearchInsightsController;
use Illuminate\Support\Facades\Route;

Route::get('search-insights', SearchInsightsController::class)->name('search-insights');
