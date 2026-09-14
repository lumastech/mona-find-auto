<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\Refund;
use App\Support\Roles\Role;

/**
 * Who may work the refund queue.
 *
 * Buyers can see their own refunds on their order; only Finance can clear a
 * manual card refund, because doing so asserts that money really was pushed
 * through Lenco's card process by hand.
 */
class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isFinance($user);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->isFinance($user) || $refund->user_id === $user->getKey();
    }

    public function process(User $user, Refund $refund): bool
    {
        return $this->isFinance($user) && ! $refund->status->isFinal();
    }

    private function isFinance(User $user): bool
    {
        return $user->hasRole([Role::Finance->value, Role::PlatformAdmin->value]);
    }
}
