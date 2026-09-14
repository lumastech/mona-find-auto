<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Policies;

use App\Models\User;
use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Support\Roles\Role;

/**
 * Who may change what sellers are charged.
 *
 * Platform administrators only. Finance can read every figure on the platform
 * and post corrections under dual control, but repricing a shop is a
 * commercial decision rather than an accounting one — and it is the kind of
 * change nobody notices until a payout looks wrong six weeks later.
 */
class MonetisationPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole([Role::Finance->value, Role::PlatformAdmin->value]);
    }

    public function view(User $user, MonetisationPolicy $policy): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::PlatformAdmin->value);
    }

    public function update(User $user, MonetisationPolicy $policy): bool
    {
        return $this->create($user);
    }

    /**
     * Changing the default, retiring a policy, or moving a seller onto one.
     */
    public function manage(User $user): bool
    {
        return $this->create($user);
    }
}
