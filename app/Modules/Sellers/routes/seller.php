<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sellers — Seller portal routes
|--------------------------------------------------------------------------
|
| Mounted at /seller behind the `seller` middleware group. Everything a
| business does to itself: its profile, its policies, where it is paid, the
| documents it uploaded, and where its application has got to.
|
| Nothing here changes the payment mode or the monetisation policy. Both are
| MonaFind's to set and both are visible but read-only in the portal.
|
*/

use App\Modules\Sellers\Http\Controllers\Seller\DocumentController;
use App\Modules\Sellers\Http\Controllers\Seller\PayoutAccountController;
use App\Modules\Sellers\Http\Controllers\Seller\PolicyController;
use App\Modules\Sellers\Http\Controllers\Seller\ProfileController;
use App\Modules\Sellers\Http\Controllers\Seller\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

Route::get('policies', [PolicyController::class, 'index'])->name('policies.index');
Route::post('policies/{type}', [PolicyController::class, 'store'])->name('policies.store');

Route::get('payout-accounts', [PayoutAccountController::class, 'index'])->name('payout-accounts.index');
Route::post('payout-accounts', [PayoutAccountController::class, 'store'])->name('payout-accounts.store');
Route::put('payout-accounts/{payoutAccount}/default', [PayoutAccountController::class, 'makeDefault'])->name('payout-accounts.default');
Route::delete('payout-accounts/{payoutAccount}', [PayoutAccountController::class, 'destroy'])->name('payout-accounts.destroy');

Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
Route::delete('documents/{media}', [DocumentController::class, 'destroy'])->name('documents.destroy');

Route::get('verification', [VerificationController::class, 'show'])->name('verification.show');
