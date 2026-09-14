<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catalog — Staff console routes
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the `admin` middleware group. The listing index
| doubles as the moderation queue; every decision below is gated by
| ProductPolicy and writes both a review event and an audit row.
|
| The inspection badge has its own endpoint rather than riding along with a
| publish decision: a listing can be perfectly publishable and uninspected,
| and marking one Inspected is MonaFind vouching for the part itself.
|
*/

use App\Modules\Catalog\Http\Controllers\Admin\CategoryController;
use App\Modules\Catalog\Http\Controllers\Admin\ListingInspectionController;
use App\Modules\Catalog\Http\Controllers\Admin\ListingModerationController;
use App\Modules\Catalog\Http\Controllers\Admin\MakeController;
use App\Modules\Catalog\Http\Controllers\Admin\ReferenceDataController;
use App\Modules\Catalog\Http\Controllers\Admin\VehicleModelController;
use Illuminate\Support\Facades\Route;

Route::get('listings', [ListingModerationController::class, 'index'])->name('listings.index');
Route::get('listings/{product}', [ListingModerationController::class, 'show'])->name('listings.show');
Route::post('listings/{product}/publish', [ListingModerationController::class, 'publish'])->name('listings.publish');
Route::post('listings/{product}/reject', [ListingModerationController::class, 'reject'])->name('listings.reject');
Route::post('listings/{product}/unpublish', [ListingModerationController::class, 'unpublish'])->name('listings.unpublish');

/* Staff only, always: the badge is MonaFind's claim about the part. */
Route::put('listings/{product}/inspection', [ListingInspectionController::class, 'update'])->name('listings.inspection');

/* The reference lists sellers pick from and buyers filter on. */
Route::get('reference', [ReferenceDataController::class, 'index'])->name('reference.index');
Route::post('reference/makes', [MakeController::class, 'store'])->name('reference.makes.store');
Route::put('reference/makes/{make}', [MakeController::class, 'update'])->name('reference.makes.update');
Route::post('reference/vehicle-models', [VehicleModelController::class, 'store'])->name('reference.vehicle-models.store');
Route::put('reference/vehicle-models/{vehicleModel}', [VehicleModelController::class, 'update'])->name('reference.vehicle-models.update');
Route::post('reference/categories', [CategoryController::class, 'store'])->name('reference.categories.store');
Route::put('reference/categories/{category}', [CategoryController::class, 'update'])->name('reference.categories.update');
