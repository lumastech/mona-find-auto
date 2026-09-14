<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin — the two public doors
|--------------------------------------------------------------------------
|
| Mounted at / with the "web" middleware group.
|
| The staff console is not the only thing this module produces. It publishes
| the pages MonaFind writes about itself, which guests read; and it sends
| invitations, which are followed by people who are not staff yet and would be
| turned away by the console's own middleware.
|
| The page route is declared last of all module web routes it could collide
| with — it matches a bare slug, so anything more specific must be declared
| before it. Hence the explicit prefix rather than a catch-all at "/".
|
*/

use App\Modules\Admin\Http\Controllers\Storefront\ContentPageController;
use App\Modules\Admin\Http\Controllers\Storefront\StaffInvitationController;
use Illuminate\Support\Facades\Route;

Route::get('pages/{page}', [ContentPageController::class, 'show'])->name('pages.show');

/*
 * Signed in before accepting: the role attaches to an account, and the
 * invitee has to own the credentials that will carry it.
 */
Route::get('staff-invitations/{token}', [StaffInvitationController::class, 'show'])
    ->name('staff-invitations.show');
Route::post('staff-invitations/{token}', [StaffInvitationController::class, 'accept'])
    ->middleware(['auth', 'verified'])
    ->name('staff-invitations.accept');
