<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catalog — Storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group, open to guests. These are the
| server-rendered pages search engines crawl, which is where most buyers
| arrive from — the listing page and the category browse above it.
|
| The listing slug route is declared last so "parts" and its own children are
| never swallowed by it.
|
*/

use App\Modules\Catalog\Http\Controllers\Storefront\CategoryBrowseController;
use App\Modules\Catalog\Http\Controllers\Storefront\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('parts', [CategoryBrowseController::class, 'index'])->name('categories.index');
Route::get('parts/{category}', [CategoryBrowseController::class, 'show'])->name('categories.show');

Route::get('listings/{product}', [ProductController::class, 'show'])->name('listings.show');
