<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Identity — staff console routes
|--------------------------------------------------------------------------
|
| Mounted at /admin with the "admin" middleware group. Every action here is
| gated by UserPolicy and writes an audit row.
|
*/

use App\Modules\Identity\Http\Controllers\Admin\UserController;
use App\Modules\Identity\Http\Controllers\Admin\UserModerationController;
use Illuminate\Support\Facades\Route;

Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');

Route::post('users/{user}/warn', [UserModerationController::class, 'warn'])->name('users.warn');
Route::post('users/{user}/suspend', [UserModerationController::class, 'suspend'])->name('users.suspend');
Route::post('users/{user}/reinstate', [UserModerationController::class, 'reinstate'])->name('users.reinstate');
Route::post('users/{user}/close', [UserModerationController::class, 'close'])->name('users.close');
