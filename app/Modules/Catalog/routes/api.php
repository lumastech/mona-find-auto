<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catalog — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group.
|
| Public, like the storefront: guests may browse published listings. The
| seller contact block follows the same blur rule as the web page, applied by
| the one resource both surfaces answer through.
|
| Bound by id rather than slug — the mobile app holds ids.
|
*/

use App\Modules\Catalog\Http\Controllers\Api\CategoryController;
use App\Modules\Catalog\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('products', [ProductController::class, 'index'])->name('products.index');
Route::get('products/{product:id}', [ProductController::class, 'show'])->name('products.show');

/*
 * Browsing by category, for the buyer who does not know what the part is
 * called and whom search therefore cannot help. Bound by slug, which is what
 * the tree endpoint hands the client.
 */
Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('categories/{slug}', [CategoryController::class, 'show'])
    ->middleware('throttle:search')
    ->name('categories.show');
