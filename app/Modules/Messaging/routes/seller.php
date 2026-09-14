<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Messaging — Seller routes
|--------------------------------------------------------------------------
|
| The shop's inbox, scoped to the business rather than to whoever is signed
| in — a buyer who wrote on Tuesday should not have to wait for the
| particular member of counter staff who was on that day.
|
| Replying goes through the storefront route and its policy. There is one
| implementation of "may this person write here", and it asks about
| participants.
|
*/

use App\Modules\Messaging\Http\Controllers\Seller\InboxController;
use Illuminate\Support\Facades\Route;

Route::get('messages', [InboxController::class, 'index'])->name('messages.index');
Route::get('messages/{thread}', [InboxController::class, 'show'])->name('messages.show');
