<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Identity — API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api.v1" middleware group. These mirror the web
| flows for the mobile app and answer in the ApiResponse envelope.
|
*/

use App\Modules\Identity\Http\Controllers\Api\AddressController;
use App\Modules\Identity\Http\Controllers\Api\AuthController;
use App\Modules\Identity\Http\Controllers\Api\PasswordResetController;
use App\Modules\Identity\Http\Controllers\Api\PhoneVerificationController;
use App\Modules\Identity\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:register')
        ->name('register');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login');

    /*
     * Password reset over SMS. Each send costs a message, so the throttle is
     * tight; the response is deliberately identical whether or not the number
     * belongs to an account.
     */
    Route::post('forgot-password', [PasswordResetController::class, 'request'])
        ->middleware('throttle:5,1')
        ->name('password.request');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1')
        ->name('password.reset');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});

/*
 * Phone verification.
 *
 * Registration leaves an account Pending and it becomes Active only when its
 * number is proven, so without these the JSON API could create an account it
 * could not finish. Behind auth but NOT behind `verified` — an account stuck
 * at this step has nowhere else to go.
 *
 * Each send costs an SMS, hence the throttles at the declaration.
 */
Route::middleware('auth:sanctum')->prefix('phone')->name('phone.')->group(function (): void {
    Route::get('/', [PhoneVerificationController::class, 'show'])->name('show');
    Route::post('/', [PhoneVerificationController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('store');
    Route::post('resend', [PhoneVerificationController::class, 'resend'])
        ->middleware('throttle:5,1')
        ->name('resend');
    Route::post('verify', [PhoneVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('verify');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:10,1')
        ->name('profile.password');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('addresses', AddressController::class);
});
