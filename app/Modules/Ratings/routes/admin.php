<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ratings — Admin routes
|--------------------------------------------------------------------------
|
| Staff console routes, mounted at /admin behind the "admin" middleware group.
|
| Two screens: the queue of reviews waiting on a decision, and the list of
| sellers whose reputation says somebody should be having a conversation with
| them.
|
*/

use App\Modules\Ratings\Http\Controllers\Admin\RatingModerationController;
use App\Modules\Ratings\Http\Controllers\Admin\TrustController;
use Illuminate\Support\Facades\Route;

Route::get('ratings', [RatingModerationController::class, 'index'])->name('ratings.index');
Route::post('ratings/{rating}/hide', [RatingModerationController::class, 'hide'])->name('ratings.hide');
Route::post('ratings/{rating}/restore', [RatingModerationController::class, 'restore'])->name('ratings.restore');

Route::get('trust', [TrustController::class, 'index'])->name('trust.index');
