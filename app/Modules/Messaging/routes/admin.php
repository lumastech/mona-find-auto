<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Messaging — Admin routes
|--------------------------------------------------------------------------
|
| Two screens, and the second is the interesting one.
|
| The matrix decides what the platform sends and over what. It is the lever
| that stops an SMS bill, so it is reachable and audited.
|
| The dispute thread is the ONLY staff door into a private conversation, and
| it is deliberately hung off the dispute rather than off the thread: there is
| no route here that lists conversations or takes a thread id, so a moderator
| cannot browse to one. The policy re-checks that the order really is
| disputed, so the narrowness does not depend on this file staying honest.
|
*/

use App\Modules\Messaging\Http\Controllers\Admin\DisputeThreadController;
use App\Modules\Messaging\Http\Controllers\Admin\NotificationMatrixController;
use Illuminate\Support\Facades\Route;

Route::get('notifications/matrix', [NotificationMatrixController::class, 'edit'])->name('notifications.matrix.edit');
Route::put('notifications/matrix', [NotificationMatrixController::class, 'update'])->name('notifications.matrix.update');

Route::get('disputes/{dispute}/thread', [DisputeThreadController::class, 'show'])->name('disputes.thread');
