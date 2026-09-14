<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\PayoutBatch;
use App\Support\Roles\Role;

/**
 * Who may see and move a payout run.
 *
 * Finance and platform admins only — no moderator, no seller. And `approve`
 * carries the dual-control rule as well as the role check, so the button is
 * hidden from the person who built the batch rather than merely failing when
 * they press it.
 */
class PayoutBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isFinance($user);
    }

    public function view(User $user, PayoutBatch $batch): bool
    {
        return $this->isFinance($user);
    }

    public function create(User $user): bool
    {
        return $this->isFinance($user);
    }

    /**
     * Dual control, enforced here as well as in the service.
     */
    public function approve(User $user, PayoutBatch $batch): bool
    {
        return $this->isFinance($user) && $batch->isApprovableBy($user);
    }

    public function cancel(User $user, PayoutBatch $batch): bool
    {
        return $this->isFinance($user) && ! $batch->status->hasStarted();
    }

    private function isFinance(User $user): bool
    {
        return $user->hasRole([Role::Finance->value, Role::PlatformAdmin->value]);
    }
}
