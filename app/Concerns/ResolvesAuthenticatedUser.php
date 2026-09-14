<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;

/**
 * Narrows a form request's user() to the application's User model.
 *
 * Requests using this trait sit behind the auth middleware, so a user is
 * always present; this makes that guarantee explicit instead of leaving every
 * call site to cope with null.
 */
trait ResolvesAuthenticatedUser
{
    /**
     * @param  string|null  $guard
     *
     * @throws AuthenticationException
     */
    public function user($guard = null): User
    {
        $user = parent::user($guard);

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
