<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mechanics — Seller portal routes
|--------------------------------------------------------------------------
|
| Mounted at /seller behind the "seller" middleware group.
|
| Where a shop reads the endorsement requests it has been sent and decides
| them. Every action is gated by EndorsementPolicy and checked again inside
| EndorsementService: only the shop a request was addressed to can answer it.
|
*/

use App\Modules\Mechanics\Http\Controllers\Seller\EndorsementController;
use Illuminate\Support\Facades\Route;

Route::get('endorsements', [EndorsementController::class, 'index'])->name('endorsements.index');

Route::post('endorsements/{endorsement}/endorse', [EndorsementController::class, 'endorse'])->name('endorsements.endorse');
Route::post('endorsements/{endorsement}/decline', [EndorsementController::class, 'decline'])->name('endorsements.decline');
Route::post('endorsements/{endorsement}/revoke', [EndorsementController::class, 'revoke'])->name('endorsements.revoke');
