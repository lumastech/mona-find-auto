<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sellers — Staff console routes
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the `admin` middleware group. The seller list
| doubles as the verification queue; every decision below is gated by
| SellerAccessPolicy and writes both a workflow event and an audit row.
|
*/

use App\Modules\Sellers\Http\Controllers\Admin\SellerController;
use App\Modules\Sellers\Http\Controllers\Admin\SellerDocumentController;
use App\Modules\Sellers\Http\Controllers\Admin\SellerVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('sellers', [SellerController::class, 'index'])->name('sellers.index');
Route::get('sellers/{seller}', [SellerController::class, 'show'])->name('sellers.show');

/* Private disk; this is the only way to read a seller's paperwork. */
Route::get('sellers/{seller}/documents/{media}', [SellerDocumentController::class, 'show'])->name('sellers.documents.show');

Route::post('sellers/{seller}/review', [SellerVerificationController::class, 'beginReview'])->name('sellers.review');
Route::post('sellers/{seller}/inspection', [SellerVerificationController::class, 'scheduleInspection'])->name('sellers.inspection');
Route::post('sellers/{seller}/verify', [SellerVerificationController::class, 'verify'])->name('sellers.verify');
Route::post('sellers/{seller}/reject', [SellerVerificationController::class, 'reject'])->name('sellers.reject');
Route::post('sellers/{seller}/suspend', [SellerVerificationController::class, 'suspend'])->name('sellers.suspend');
Route::post('sellers/{seller}/reinstate', [SellerVerificationController::class, 'reinstate'])->name('sellers.reinstate');
