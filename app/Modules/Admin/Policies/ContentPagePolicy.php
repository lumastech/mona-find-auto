<?php

declare(strict_types=1);

namespace App\Modules\Admin\Policies;

use App\Models\User;
use App\Modules\Admin\Models\ContentPage;
use App\Support\Roles\Role;

/**
 * Who may write what MonaFind says about itself.
 *
 * Moderators write the ordinary pages — About, the FAQ, Contact — because
 * that is editorial work and the people doing it are the ones fielding the
 * questions. The platform terms and the privacy notice are not editorial:
 * their text is what MonaFind is legally held to and their version number is
 * recorded against every buyer's acceptance, so a platform administrator
 * publishes those.
 */
class ContentPagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Moderator->value, Role::PlatformAdmin->value]);
    }

    public function view(User $user, ContentPage $page): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Publishing a new version.
     */
    public function publish(User $user, ContentPage $page): bool
    {
        if ($page->is_system) {
            return $user->hasRole(Role::PlatformAdmin->value);
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::PlatformAdmin->value);
    }

    /**
     * A system page is never deleted: something elsewhere renders it, and
     * deleting one would leave that with nothing to show.
     */
    public function delete(User $user, ContentPage $page): bool
    {
        return ! $page->is_system && $user->hasRole(Role::PlatformAdmin->value);
    }
}
