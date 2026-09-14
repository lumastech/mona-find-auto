<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| The staff console has no API and is not going to get one — it is a web
| surface with no mobile counterpart. What IS here is the other thing this
| module publishes: the pages MonaFind writes about itself, which the app
| needs in order to show somebody the terms it is asking them to accept.
|
| Open to guests, like the web equivalent.
|
*/

use App\Modules\Admin\Http\Controllers\Api\ContentPageController;
use Illuminate\Support\Facades\Route;

Route::get('pages', [ContentPageController::class, 'index'])->name('pages.index');
Route::get('pages/{slug}', [ContentPageController::class, 'show'])->name('pages.show');
