<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Messaging — API v1 routes
|--------------------------------------------------------------------------
|
| The mobile app's bell and inbox. Everything here needs a token: there is no
| public half of somebody's notifications or somebody's conversations.
|
| `/threads` answers for both sides — `?role=seller` asks for the shop's inbox
| — which is the same arrangement quotations use, and for the same reason:
| it is one resource seen from two directions, not two resources.
|
*/

use App\Modules\Messaging\Http\Controllers\Api\NotificationController;
use App\Modules\Messaging\Http\Controllers\Api\ThreadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::get('notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');
    Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::get('threads', [ThreadController::class, 'index'])->name('threads.index');
    Route::post('threads', [ThreadController::class, 'store'])->name('threads.store');
    Route::get('threads/{thread}', [ThreadController::class, 'show'])->name('threads.show');
    Route::post('threads/{thread}/messages', [ThreadController::class, 'reply'])->name('threads.messages.store');
    Route::post('threads/{thread}/read', [ThreadController::class, 'markRead'])->name('threads.read');
});
