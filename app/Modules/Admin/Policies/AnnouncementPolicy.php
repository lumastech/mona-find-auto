<?php

declare(strict_types=1);

namespace App\Modules\Admin\Policies;

use App\Models\User;
use App\Support\Roles\Role;

/**
 * Who may put a banner across the top of the platform.
 *
 * Moderators may, because the announcements that matter most are operational
 * — a region undelivered, a payment channel down — and they are the people
 * who find out first. Finance is not on this list: a banner is a statement
 * MonaFind makes to every visitor, and it is not finance work.
 */
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Moderator->value, Role::PlatformAdmin->value]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user): bool
    {
        return $this->viewAny($user);
    }
}
