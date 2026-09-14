<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Events;

use App\Modules\Sellers\Models\SellerPolicy;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new version of a seller policy came into force.
 *
 * Buyers accept a specific version at checkout, so Orders listens for this
 * to know that carts holding the previous version need re-accepting.
 */
class SellerPolicyPublished
{
    use Dispatchable;

    public function __construct(
        public readonly SellerPolicy $policy,
        public readonly ?SellerPolicy $replaced = null,
    ) {}
}
