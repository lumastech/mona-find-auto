<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Models\User;
use App\Support\Roles\Role;

/**
 * Who may look at and act on somebody else's account.
 *
 * Moderation is staff work, but nobody moderates themselves and nobody
 * suspends a platform administrator except another platform administrator —
 * that is what stops one compromised moderator account from locking the
 * platform's owners out.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::staffConsole());
    }

    public function view(User $user, User $subject): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Warning, suspending, reinstating and closing all share one gate.
     */
    public function moderate(User $user, User $subject): bool
    {
        if (! $user->hasAnyRole(Role::staffConsole())) {
            return false;
        }

        if ($user->is($subject)) {
            return false;
        }

        if ($subject->hasRole(Role::PlatformAdmin->value)) {
            return $user->hasRole(Role::PlatformAdmin->value);
        }

        return true;
    }

    /**
     * Only a platform administrator hands out roles.
     */
    public function assignRoles(User $user, User $subject): bool
    {
        return $user->hasRole(Role::PlatformAdmin->value) && ! $user->is($subject);
    }
}
