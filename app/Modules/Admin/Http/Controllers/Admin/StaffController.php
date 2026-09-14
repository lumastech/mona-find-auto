<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Http\Requests\Admin\DeactivateStaffRequest;
use App\Modules\Admin\Http\Requests\Admin\InviteStaffRequest;
use App\Modules\Admin\Http\Requests\Admin\RevokeInvitationRequest;
use App\Modules\Admin\Http\Requests\Admin\UpdateStaffRolesRequest;
use App\Modules\Admin\Models\StaffInvitation;
use App\Modules\Admin\Services\StaffDirectory;
use App\Support\Roles\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Who works for MonaFind and what they may touch.
 *
 * Platform administrators only — the whole screen, not merely the buttons. A
 * moderator who could read this list could read which finance accounts exist
 * and which of them have not enrolled in two-factor authentication, which is
 * a shopping list.
 *
 * The permission matrix this screen hands out is documented in the module
 * README rather than being inferable from the checkboxes.
 */
class StaffController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly StaffDirectory $staff) {}

    public function index(Request $request): Response
    {
        Gate::authorize('admin-only');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string'],
        ]);

        $role = Role::tryFrom($filters['role'] ?? '');

        return Inertia::render('admin/staff/Index', [
            'staff' => $this->staff->paginate($filters['search'] ?? null, $role)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status->value,
                    'status_label' => $user->status->label(),
                    'roles' => $user->getRoleNames()->values()->all(),
                    /*
                     * Enrolment is enforced by middleware everywhere; this
                     * column exists so somebody can chase the person rather
                     * than wait for them to notice they are locked out.
                     */
                    'two_factor_ready' => $user->hasConfirmedTwoFactor(),
                    'is_self' => $user->is($request->user()),
                    'href' => route('admin.users.show', $user),
                ]),
            'filters' => ['search' => $filters['search'] ?? null, 'role' => $role?->value],
            'roles' => array_map(static fn (string $value): array => [
                'value' => $value,
                'label' => Role::from($value)->label(),
            ], Role::staffConsole()),
            'invitations' => $this->invitations(),
            'twoFactorOutstanding' => $this->staff->awaitingTwoFactor(),
        ]);
    }

    public function invite(InviteStaffRequest $request): RedirectResponse
    {
        Gate::authorize('admin-only');

        $result = $this->staff->invite(
            email: (string) $request->validated('email'),
            name: (string) $request->validated('name'),
            role: $request->role(),
            actor: $this->currentUser($request),
            reason: $request->reason(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Invitation sent to :email.', ['email' => $result['invitation']->email]),
        ]);

        /*
         * Shown once and never stored in the clear. The queue that sends the
         * email can be minutes behind; an administrator standing next to the
         * new starter should not have to wait for it.
         */
        Inertia::flash('invitationLink', route('staff-invitations.show', $result['token']));

        return back();
    }

    public function updateRoles(UpdateStaffRolesRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('admin-only');

        /*
         * Deliberately not UserPolicy::assignRoles, which refuses to let
         * anybody touch their own roles. Stepping down — handing the platform
         * to a successor and dropping to finance — is a legitimate act, and
         * the thing that actually has to be prevented is the lockout it could
         * cause. StaffDirectory refuses to remove the LAST platform
         * administrator, from anybody including themselves, which covers that
         * case and the case a blanket self-block does not: an administrator
         * removing the role from the only other holder.
         */
        $this->guardAgainstLockout(fn () => $this->staff->setStaffRoles(
            $user,
            $request->roles(),
            $this->currentUser($request),
            $request->reason(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Roles updated.')]);

        return back();
    }

    public function deactivate(DeactivateStaffRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('admin-only');
        Gate::authorize('moderate', $user);

        $this->guardAgainstLockout(
            fn () => $this->staff->deactivate($user, $this->currentUser($request), $request->reason()),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account deactivated and signed out everywhere.')]);

        return back();
    }

    public function reinstate(DeactivateStaffRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('admin-only');
        Gate::authorize('moderate', $user);

        $this->staff->reinstate($user, $this->currentUser($request), $request->reason());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account reinstated.')]);

        return back();
    }

    public function revokeInvitation(RevokeInvitationRequest $request, StaffInvitation $invitation): RedirectResponse
    {
        Gate::authorize('admin-only');

        $this->staff->revokeInvitation($invitation, $this->currentUser($request), $request->reason());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return back();
    }

    /**
     * Turn the last-administrator refusal into a field error.
     *
     * It is a rule about the platform's state rather than about this request,
     * so the service raises it; the operator still needs to see it on the
     * form rather than as a 500.
     *
     * @param  callable(): mixed  $action
     */
    private function guardAgainstLockout(callable $action): void
    {
        try {
            $action();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function invitations(): array
    {
        return StaffInvitation::query()
            ->with('inviter:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static fn (StaffInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->role,
                'role_label' => $invitation->roleEnum()?->label() ?? $invitation->role,
                'status' => $invitation->status()->value,
                'status_label' => $invitation->status()->label(),
                'badge' => $invitation->status()->badgeVariant(),
                'invited_by' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'is_open' => $invitation->isOpen(),
            ])
            ->all();
    }
}
