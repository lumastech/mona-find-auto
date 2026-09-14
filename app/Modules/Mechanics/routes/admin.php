<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mechanics — Staff console routes
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the `admin` middleware group. The mechanic list
| doubles as the approval queue; every decision below is gated by
| MechanicProfilePolicy and writes an audit row.
|
| This is also the only place the references and certificates are readable.
|
*/

use App\Modules\Mechanics\Http\Controllers\Admin\MechanicApprovalController;
use App\Modules\Mechanics\Http\Controllers\Admin\MechanicController;
use Illuminate\Support\Facades\Route;

Route::get('mechanics', [MechanicController::class, 'index'])->name('mechanics.index');
Route::get('mechanics/{mechanic}', [MechanicController::class, 'show'])->name('mechanics.show');

Route::post('mechanics/{mechanic}/review', [MechanicApprovalController::class, 'beginReview'])->name('mechanics.review');
Route::post('mechanics/{mechanic}/approve', [MechanicApprovalController::class, 'approve'])->name('mechanics.approve');
Route::post('mechanics/{mechanic}/reject', [MechanicApprovalController::class, 'reject'])->name('mechanics.reject');
Route::post('mechanics/{mechanic}/suspend', [MechanicApprovalController::class, 'suspend'])->name('mechanics.suspend');
Route::post('mechanics/{mechanic}/reinstate', [MechanicApprovalController::class, 'reinstate'])->name('mechanics.reinstate');
