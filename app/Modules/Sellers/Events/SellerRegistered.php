<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Events;

use App\Modules\Sellers\Models\Seller;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sign-up wizard finished and became a real seller.
 *
 * The application has not been reviewed yet — SellerVerified is the event
 * that means somebody checked it.
 */
class SellerRegistered
{
    use Dispatchable;

    public function __construct(public readonly Seller $seller) {}
}
