<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shopping — Seller routes
|--------------------------------------------------------------------------
|
| Mounted at /seller behind the "seller" middleware group.
|
| The RFQ inbox. Holding the seller role opens these routes; being the shop a
| particular request was addressed to is a separate question, asked of
| QuotationPolicy inside the controller — a price is a commitment to sell at
| it, and no shop may be committed by another shop's account.
|
*/

use App\Modules\Shopping\Http\Controllers\Seller\QuotationController;
use Illuminate\Support\Facades\Route;

Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
Route::post('quotations/{quotation}/respond', [QuotationController::class, 'respond'])->name('quotations.respond');
Route::post('quotations/{quotation}/decline', [QuotationController::class, 'decline'])->name('quotations.decline');
