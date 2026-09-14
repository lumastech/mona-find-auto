<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\StaffInvitation;
use App\Modules\Admin\Services\StaffDirectory;
use App\Support\Roles\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Accepting an invitation to join MonaFind staff.
 *
 * This lives on the storefront rather than in /admin for the obvious reason:
 * the person following the link is not staff yet, and the console would
 * refuse them at the door.
 *
 * Acceptance attaches a role to an ACCOUNT, which is why an invitee has to
 * sign in — or register — first. There is no path here that creates an
 * account from a token: a staff account the platform created on somebody's
 * behalf would have a password nobody chose, and the invitee has to own the
 * credentials that will be carrying a moderator role.
 *
 * The two-factor requirement then applies automatically. EnsureStaffTwoFactor
 * holds the newly-promoted account at the enrolment screen on its very next
 * request, whatever it tries to open.
 */
class StaffInvitationController extends Controller
{
    public function __construct(private readonly StaffDirectory $staff) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->staff->findOpenInvitation($token);
        $user = $request->user();

        return Inertia::render('storefront/StaffInvitation', [
            'token' => $token,
            'invitation' => $invitation === null ? null : [
                'name' => $invitation->name,
                'email' => $invitation->email,
                'role_label' => $invitation->roleEnum()?->label() ?? $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            /*
             * Whether the signed-in account is the right one. A staff
             * invitation forwarded to a colleague who is already signed in as
             * themselves is the case this catches before they press accept.
             */
            'signedInAs' => $user?->email,
            'emailMatches' => $invitation !== null
                && $user !== null
                && strcasecmp($invitation->email, $user->email) === 0,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->staff->findOpenInvitation($token);

        abort_if($invitation === null, 404, 'This invitation is no longer valid.');

        $user = $request->user();

        abort_if($user === null, 403);

        try {
            $this->staff->accept($invitation, $user);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['token' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Welcome to the MonaFind staff console.'),
        ]);

        return $this->destinationFor($invitation);
    }

    private function destinationFor(StaffInvitation $invitation): RedirectResponse
    {
        return $invitation->roleEnum() instanceof Role
            ? redirect()->route('admin.dashboard')
            : redirect()->route('home');
    }
}
