<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Policies;

use App\Models\User;
use App\Modules\Ledger\Models\LedgerAdjustment;
use App\Support\Roles\Role;

/**
 * The two-person rule, expressed as a policy.
 *
 * Finance drafts. A platform administrator decides. And whoever decides must
 * not be whoever drafted — an administrator who drafts an adjustment has to
 * find a second administrator to approve it, exactly as finance would.
 *
 * The service enforces the same rule and throws rather than trusting this.
 * Both layers are deliberate: the policy is what hides the button, and the
 * service is what makes the rule true.
 */
class LedgerAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole([Role::Finance->value, Role::PlatformAdmin->value]);
    }

    public function view(User $user, LedgerAdjustment $adjustment): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Draft an adjustment. Moves no money on its own.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Approve or reject one — which is what actually posts.
     */
    public function decide(User $user, LedgerAdjustment $adjustment): bool
    {
        return $user->hasRole(Role::PlatformAdmin->value) && $adjustment->isDecidableBy($user);
    }
}
