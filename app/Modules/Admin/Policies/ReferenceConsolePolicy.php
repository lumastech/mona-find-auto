<?php

declare(strict_types=1);

namespace App\Modules\Admin\Policies;

use App\Models\User;
use App\Support\Roles\Role;

/**
 * Who may curate the platform's vocabulary, and who may merge two entries.
 *
 * Not a model policy — the console spans six lists owned by three modules,
 * and the question it answers is about the act rather than about any one
 * row. Registered as a gate in AdminServiceProvider.
 *
 * Merging is separated from editing because it is the only destructive thing
 * on the screen: it rewrites foreign keys across tables and deletes a row.
 * Renaming "Toyata" fixes the label; merging it moves every listing behind
 * it, and there is no undo.
 */
class ReferenceConsolePolicy
{
    public function view(User $user): bool
    {
        return $user->hasAnyRole(Role::staffConsole());
    }

    public function manage(User $user): bool
    {
        return $user->hasAnyRole([Role::Moderator->value, Role::PlatformAdmin->value]);
    }

    public function merge(User $user): bool
    {
        return $user->hasRole(Role::PlatformAdmin->value);
    }
}
