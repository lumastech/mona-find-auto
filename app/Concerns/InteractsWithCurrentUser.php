<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Narrows a controller's authenticated user to the application's User model.
 *
 * Controllers using this sit behind the auth middleware, so a user is always
 * present. This states that guarantee once instead of leaving every action to
 * cope with a null it can never actually receive.
 */
trait InteractsWithCurrentUser
{
    /**
     * @throws AuthenticationException
     */
    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
