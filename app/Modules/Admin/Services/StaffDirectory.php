<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Models\StaffInvitation;
use App\Modules\Admin\Notifications\StaffInvitationNotification;
use App\Modules\Identity\Services\AccountModerationService;
use App\Support\Roles\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Who works for MonaFind, and what they may touch.
 *
 * Staff roles are never self-service and never granted quietly. A moderator
 * can unpublish any shop's entire stock; a finance user can move real money
 * out of the platform. So there are exactly two ways into one of these roles
 * — an invitation a platform administrator sent to a named address, or that
 * same administrator adding the role to an existing account — and both write
 * an audit row carrying a reason.
 *
 * Removing the last platform administrator is refused outright. It is the one
 * mistake on this screen with no way back: there would be nobody left who
 * could put the role on anybody, including themselves.
 */
class StaffDirectory
{
    /** How long an invitation stays open. */
    public const INVITATION_DAYS = 7;

    public function __construct(private readonly AccountModerationService $accounts) {}

    /**
     * Everybody currently holding a staff role.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?string $search = null, ?Role $role = null, int $perPage = 25): LengthAwarePaginator
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn(
                'name',
                $role instanceof Role ? [$role->value] : Role::staffConsole(),
            ))
            ->with('roles:id,name')
            ->search($search)
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Send an invitation, returning it with its one-time token.
     *
     * The token is returned rather than stored in the clear so the console
     * can show it once — a Zambian staff member whose mail is slow should not
     * be locked out waiting for a queue to drain.
     *
     * @return array{invitation: StaffInvitation, token: string}
     */
    public function invite(string $email, string $name, Role $role, User $actor, ?string $reason = null): array
    {
        $email = Str::lower(trim($email));
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($email, $name, $role, $actor, $token): StaffInvitation {
            /* One open invitation per address: a second is a resend, not a queue. */
            StaffInvitation::query()
                ->where('email', $email)
                ->open()
                ->update(['revoked_at' => now(), 'revoked_reason' => 'Superseded by a newer invitation.']);

            return StaffInvitation::query()->create([
                'email' => $email,
                'name' => $name,
                'role' => $role->value,
                'token_hash' => $this->hash($token),
                'expires_at' => now()->addDays(self::INVITATION_DAYS),
                'invited_by' => $actor->getKey(),
            ]);
        });

        Notification::route('mail', $email)
            ->notify(new StaffInvitationNotification($invitation->load('inviter'), $token));

        audit($actor, 'staff.invited', $invitation, null, [
            'email' => $email,
            'role' => $role->value,
        ], $reason);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * The open invitation this token belongs to, if any.
     */
    public function findOpenInvitation(string $token): ?StaffInvitation
    {
        return StaffInvitation::query()
            ->where('token_hash', $this->hash($token))
            ->open()
            ->first();
    }

    /**
     * Put the invited role onto the account that accepted it.
     *
     * The email has to match. Without that check, forwarding the message
     * would be enough to hand the role to somebody nobody invited.
     */
    public function accept(StaffInvitation $invitation, User $user): User
    {
        if (! hash_equals(Str::lower($invitation->email), Str::lower($user->email))) {
            throw new RuntimeException('This invitation was sent to a different email address.');
        }

        $role = $invitation->roleEnum();

        if (! $role instanceof Role) {
            throw new RuntimeException('This invitation names a role that no longer exists.');
        }

        DB::transaction(function () use ($invitation, $user, $role): void {
            $user->assignRole($role->value);

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_user_id' => $user->getKey(),
            ])->save();
        });

        audit($user, 'staff.invitation.accepted', $user, null, [
            'role' => $role->value,
            'invitation_id' => $invitation->id,
        ], 'Invitation accepted.');

        return $user->refresh();
    }

    public function revokeInvitation(StaffInvitation $invitation, User $actor, string $reason): StaffInvitation
    {
        $invitation->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();

        audit($actor, 'staff.invitation.revoked', $invitation, null, ['email' => $invitation->email], $reason);

        return $invitation;
    }

    /**
     * Set exactly which staff roles an account holds.
     *
     * Non-staff roles the account also has — being a buyer, running a shop —
     * are left alone: a moderator who also buys parts does not stop being a
     * buyer because somebody edited their staff roles.
     *
     * @param  array<int, Role>  $roles
     */
    public function setStaffRoles(User $user, array $roles, User $actor, string $reason): User
    {
        $before = $user->getRoleNames()->values()->all();
        $wanted = array_values(array_unique(array_map(static fn (Role $role): string => $role->value, $roles)));

        $this->guardLastAdministrator($user, $wanted);

        foreach (Role::staffConsole() as $staffRole) {
            if (in_array($staffRole, $wanted, true)) {
                $user->assignRole($staffRole);

                continue;
            }

            $user->removeRole($staffRole);
        }

        $user->refresh();

        audit($actor, 'staff.roles.changed', $user, ['roles' => $before], [
            'roles' => $user->getRoleNames()->values()->all(),
        ], $reason);

        return $user;
    }

    /**
     * Take somebody off the console.
     *
     * Suspension rather than role removal, because a staff account that has
     * gone bad should stop working everywhere at once — the suspension ends
     * their live sessions, which stripping a role would not.
     */
    public function deactivate(User $user, User $actor, string $reason): User
    {
        $this->guardLastAdministrator($user, []);

        return $this->accounts->suspend($user, $reason, $actor);
    }

    public function reinstate(User $user, User $actor, string $reason): User
    {
        return $this->accounts->reinstate($user, $reason, $actor);
    }

    /**
     * Whether anybody on the console still has 2FA to set up.
     *
     * Enrolment itself is enforced by middleware on every request; this is
     * only so the console can say who is currently held at that door.
     */
    public function awaitingTwoFactor(): int
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', Role::requiringTwoFactor()))
            ->get(['id', 'two_factor_secret', 'two_factor_confirmed_at'])
            ->filter(static fn (User $user): bool => $user->mustEnrolInTwoFactor())
            ->count();
    }

    /**
     * @param  array<int, string>  $wanted
     */
    private function guardLastAdministrator(User $user, array $wanted): void
    {
        $admin = Role::PlatformAdmin->value;

        if (! $user->hasRole($admin) || in_array($admin, $wanted, true)) {
            return;
        }

        $remaining = User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', $admin))
            ->whereKeyNot($user->getKey())
            ->count();

        if ($remaining === 0) {
            throw new RuntimeException(
                'This is the last platform administrator. Give somebody else the role first, or nobody will be able to grant it again.',
            );
        }
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
