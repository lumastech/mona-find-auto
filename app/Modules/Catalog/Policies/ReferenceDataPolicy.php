<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Support\Roles\Role;

/**
 * Who may curate the reference lists: makes, models and the category tree.
 *
 * Everyone reads them — a seller picking a make, a buyer filtering a search —
 * so reading is not gated here at all. Writing is staff work: these lists are
 * what stops "Toyota", "TOYOTA" and "Toyata" becoming three makes and the
 * search facets becoming useless.
 *
 * One policy covers all three models. They answer the same question, and
 * three identical classes would only be three places to forget to change.
 */
class ReferenceDataPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::staffConsole());
    }

    public function manage(User $user): bool
    {
        return $user->hasAnyRole([Role::Moderator->value, Role::PlatformAdmin->value]);
    }

    /**
     * Deleting reference data is a platform-admin action.
     *
     * Retiring a make is the usual answer: existing listings still point at
     * it, and deleting one would take them with it.
     */
    public function delete(User $user): bool
    {
        return $user->hasRole(Role::PlatformAdmin->value);
    }
}
