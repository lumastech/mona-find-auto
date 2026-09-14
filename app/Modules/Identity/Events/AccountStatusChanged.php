<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An account moved between statuses.
 *
 * Other modules listen for this rather than watching the users table: the
 * Sellers module hides a suspended seller's listings, Orders stops assigning
 * them work, Messaging closes their threads.
 */
class AccountStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly AccountStatus $from,
        public readonly AccountStatus $to,
        public readonly ?string $reason = null,
        public readonly ?User $actor = null,
    ) {}
}
