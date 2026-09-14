<?php

declare(strict_types=1);

namespace App\Modules\Admin\Policies;

use App\Models\User;
use App\Support\Roles\Role;

/**
 * Who may read the audit trail, and who may take a copy of it away.
 *
 * Every staff role reads it. That is the point of an audit trail: a moderator
 * who can see that somebody else unpublished a listing is exactly the check
 * the trail exists to provide, and a trail only its subject cannot see is
 * worth very little.
 *
 * Exporting is narrower. A CSV of the whole trail is a file of names, email
 * addresses, IP addresses and money movements that leaves the platform and is
 * never seen again — a different act from reading a page of it on screen, and
 * one a platform administrator signs for.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::staffConsole());
    }

    public function export(User $user): bool
    {
        return $user->hasRole(Role::PlatformAdmin->value);
    }
}
