<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Identity — storefront routes
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group. Everything a person does to
| their own account lives here: proving their phone number, signing in with
| Google or Facebook, managing delivery addresses and devices.
|
*/

use App\Modules\Identity\Http\Controllers\Storefront\AddressController;
use App\Modules\Identity\Http\Controllers\Storefront\PhoneSetupController;
use App\Modules\Identity\Http\Controllers\Storefront\PhoneVerificationController;
use App\Modules\Identity\Http\Controllers\Storefront\SessionController;
use App\Modules\Identity\Http\Controllers\Storefront\SmsPasswordResetController;
use App\Modules\Identity\Http\Controllers\Storefront\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    /*
     * Password reset over SMS, alongside Fortify's email reset. The send step
     * is throttled hard: each attempt costs an SMS.
     */
    Route::get('forgot-password/sms', [SmsPasswordResetController::class, 'show'])->name('password.sms.request');
    Route::post('forgot-password/sms', [SmsPasswordResetController::class, 'send'])
        ->middleware('throttle:5,1')
        ->name('password.sms.send');
    Route::get('reset-password/sms', [SmsPasswordResetController::class, 'edit'])->name('password.sms.reset');
    Route::post('reset-password/sms', [SmsPasswordResetController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('password.sms.update');

    Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('social.redirect');
    Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('social.callback');
});

Route::middleware('auth')->group(function (): void {
    /*
     * Phone verification. Deliberately outside the "verified" middleware:
     * an account that cannot get past this screen has nowhere else to go.
     */
    Route::get('phone/verify', [PhoneVerificationController::class, 'show'])->name('phone.verify');
    Route::post('phone/verify', [PhoneVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('phone.verify.submit');
    Route::post('phone/resend', [PhoneVerificationController::class, 'send'])
        ->middleware('throttle:5,1')
        ->name('phone.resend');

    /* Where a Google or Facebook signup lands: it still owes us a phone number. */
    Route::get('phone/setup', [PhoneSetupController::class, 'show'])->name('phone.setup');
    Route::post('phone/setup', [PhoneSetupController::class, 'store'])->name('phone.setup.store');

    Route::get('settings/addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('settings/addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::patch('settings/addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('settings/addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
    Route::put('settings/addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('addresses.default');

    Route::get('settings/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('settings/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');
    Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
});
