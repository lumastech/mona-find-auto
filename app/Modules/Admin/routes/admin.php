<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin — the console's own screens
|--------------------------------------------------------------------------
|
| Mounted at /admin behind the `admin` middleware group. Every other module
| mounts its own queues into the same group; what lives HERE is the handful of
| screens that belong to no single module — the dashboard that reads all of
| them, the settings that configure all of them, the reference lists shared
| between them, the pages the platform publishes about itself, the staff who
| run it, and the audit trail that records the lot.
|
| Two things to note about ordering. The dashboard is declared as "/" and
| overrides the placeholder in routes/admin.php only because module routes
| load first; and `reference-data/{list}` sits under a literal prefix so it
| can never shadow another module's screen.
|
*/

use App\Modules\Admin\Http\Controllers\Admin\AnnouncementController;
use App\Modules\Admin\Http\Controllers\Admin\AuditLogController;
use App\Modules\Admin\Http\Controllers\Admin\ContentPageController;
use App\Modules\Admin\Http\Controllers\Admin\DashboardController;
use App\Modules\Admin\Http\Controllers\Admin\ReferenceDataController;
use App\Modules\Admin\Http\Controllers\Admin\SettingsController;
use App\Modules\Admin\Http\Controllers\Admin\StaffController;
use Illuminate\Support\Facades\Route;

/* What is waiting, gathered from every module that has a queue. */
Route::get('/', DashboardController::class)->name('dashboard');

/*
 * The platform's own numbers. Platform administrators only — see the
 * controller for why there is no smaller slice of this worth delegating.
 */
Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

/* Every curated list in one place, plus the merge tool for duplicates. */
Route::prefix('reference-data')->name('reference-data.')->group(function (): void {
    Route::get('/', [ReferenceDataController::class, 'index'])->name('index');
    Route::post('{list}', [ReferenceDataController::class, 'store'])->name('store');
    Route::put('{list}/{id}', [ReferenceDataController::class, 'update'])->name('update');
    /* Destructive, and platform-admin only: it rewrites keys and deletes a row. */
    Route::post('{list}/merge', [ReferenceDataController::class, 'merge'])->name('merge');
});

/* What MonaFind publishes about itself, and the banner across the top. */
Route::get('content', [ContentPageController::class, 'index'])->name('content.index');
Route::get('content/{page}', [ContentPageController::class, 'edit'])->name('content.edit');
Route::post('content/{page}/publish', [ContentPageController::class, 'publish'])->name('content.publish');
Route::post('content/{page}/unpublish', [ContentPageController::class, 'unpublish'])->name('content.unpublish');

Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
Route::post('announcements/{announcement}/deactivate', [AnnouncementController::class, 'deactivate'])
    ->name('announcements.deactivate');

/* Who works here. The permission matrix is in the module README. */
Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
Route::post('staff/invitations', [StaffController::class, 'invite'])->name('staff.invite');
Route::post('staff/invitations/{invitation}/revoke', [StaffController::class, 'revokeInvitation'])
    ->name('staff.invitations.revoke');
Route::put('staff/{user}/roles', [StaffController::class, 'updateRoles'])->name('staff.roles');
Route::post('staff/{user}/deactivate', [StaffController::class, 'deactivate'])->name('staff.deactivate');
Route::post('staff/{user}/reinstate', [StaffController::class, 'reinstate'])->name('staff.reinstate');

/*
 * The trail. Readable by every staff role — that is the point of it — and
 * exportable only by a platform administrator, because a CSV of it is a file
 * of names, addresses and money movements that leaves the platform.
 */
Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
Route::get('audit/export', [AuditLogController::class, 'export'])->name('audit.export');
