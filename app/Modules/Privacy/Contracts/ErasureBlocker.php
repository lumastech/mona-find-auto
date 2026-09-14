<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Contracts;

use App\Models\User;

/**
 * A module's reason for holding an erasure back.
 *
 * Implemented by any module that can be part-way through something with a
 * person — Orders while an order is in flight, Payments while a refund is
 * still moving — and registered with `ErasureGuard` from that module's
 * service provider.
 *
 * The return value is shown to the account holder, so it is a sentence
 * written for them, not a code: "You have 2 orders still in progress. Your
 * account will be deleted once they are complete."
 */
interface ErasureBlocker
{
    /**
     * Why this account cannot be erased yet, or null when it can.
     */
    public function blocksErasureOf(User $user): ?string;
}
