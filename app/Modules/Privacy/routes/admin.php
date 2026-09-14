<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Privacy — Admin routes
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the "admin" middleware group.
|
| Staff see what is coming and may hold a request while an order or a dispute
| settles. There is deliberately no cancel route: an erasure staff could
| refuse would not be a right. See ErasureRequestPolicy.
|
*/

use App\Modules\Privacy\Http\Controllers\Admin\ErasureRequestController;
use Illuminate\Support\Facades\Route;

Route::get('privacy/erasure-requests', [ErasureRequestController::class, 'index'])
    ->name('privacy.erasure-requests.index');

Route::post('privacy/erasure-requests/{erasureRequest}/block', [ErasureRequestController::class, 'block'])
    ->name('privacy.erasure-requests.block');
Route::post('privacy/erasure-requests/{erasureRequest}/release', [ErasureRequestController::class, 'release'])
    ->name('privacy.erasure-requests.release');
