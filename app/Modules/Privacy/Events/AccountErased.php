<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An account has been erased.
 *
 * Carries ids rather than the User model, and that is the point: by the time
 * a listener runs, the account holds tombstones. A serialised model would
 * either fail to find what it expected or hand a listener a row whose name
 * and email are placeholders — and a listener that logged those would be
 * writing "Former MonaFind user" into somewhere that meant to say who it was.
 *
 * Anything a listener needs to know about the person had to be dealt with
 * before the erasure, not after it.
 */
class AccountErased
{
    use Dispatchable;

    public function __construct(
        public readonly int $erasureRequestId,
        public readonly int $userId,
    ) {}
}
