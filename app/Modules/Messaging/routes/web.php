<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Messaging — Storefront routes
|--------------------------------------------------------------------------
|
| The bell, the buyer's and mechanic's inbox, and one person's notification
| preferences. All behind auth: a guest has nothing to be notified about and
| nobody to talk to.
|
| The inbox is shared between buyers and mechanics on purpose. A mechanic is a
| person with a public profile who also buys parts, not a separate kind of
| account, and a second inbox would mean messages arriving in whichever of the
| two they were not looking at.
|
*/

use App\Modules\Messaging\Http\Controllers\Storefront\NotificationController;
use App\Modules\Messaging\Http\Controllers\Storefront\NotificationPreferenceController;
use App\Modules\Messaging\Http\Controllers\Storefront\ThreadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])->name('settings.notifications.edit');
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])->name('settings.notifications.update');

    Route::get('messages', [ThreadController::class, 'index'])->name('threads.index');
    Route::post('messages', [ThreadController::class, 'store'])->name('threads.store');
    Route::get('messages/{thread}', [ThreadController::class, 'show'])->name('threads.show');
    Route::post('messages/{thread}/reply', [ThreadController::class, 'reply'])->name('threads.reply');
    Route::post('messages/{thread}/read', [ThreadController::class, 'markRead'])->name('threads.read');
});
