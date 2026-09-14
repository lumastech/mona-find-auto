<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\Identity\Actions\RegisterUser;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's entry point into registration.
 *
 * The work itself belongs to the Identity module, which the JSON API calls
 * directly, so the two paths cannot drift apart.
 */
class CreateNewUser implements CreatesNewUsers
{
    public function __construct(private readonly RegisterUser $register) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        return $this->register->handle($input, via: 'web', requestIp: request()->ip());
    }
}
