<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Orders — Admin routes
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the "admin" middleware group.
|
| Order search is read-only on purpose. The only thing staff write here is a
| dispute resolution, which carries a mandatory reason and fires the event
| Payments acts on — there is deliberately no screen where an order's history
| can be quietly rearranged.
|
*/

use App\Modules\Orders\Http\Controllers\Admin\DisputeController;
use App\Modules\Orders\Http\Controllers\Admin\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

Route::get('disputes', [DisputeController::class, 'index'])->name('disputes.index');
Route::get('disputes/{dispute}', [DisputeController::class, 'show'])->name('disputes.show');
Route::post('disputes/{dispute}/claim', [DisputeController::class, 'claim'])->name('disputes.claim');
Route::post('disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('disputes.resolve');
