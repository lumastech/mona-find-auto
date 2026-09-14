<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new account was created, however it was created — the web form, the JSON
 * API, or a Google or Facebook login.
 */
class AccountRegistered
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $via = 'web',
    ) {}
}
