<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff put a formal warning on an account. The account keeps working; the
 * warning exists so a later suspension has a documented history behind it.
 */
class AccountWarned
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $reason,
        public readonly ?User $actor = null,
    ) {}
}
