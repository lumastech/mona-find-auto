<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments — Seller routes
|--------------------------------------------------------------------------
|
| Mounted at /seller with the "seller" middleware group.
|
| Read-only by design. A seller sees what they are owed, what is held and what
| has been paid; they cannot start a payout, change their payment mode, or
| touch anything that moves money. Every figure here comes from the ledger.
|
*/

use App\Modules\Payments\Http\Controllers\Seller\EarningsController;
use Illuminate\Support\Facades\Route;

Route::get('earnings', [EarningsController::class, 'index'])->name('earnings.index');
