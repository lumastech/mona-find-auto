<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Models\User;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A seller vouched for their stock.
 *
 * Carries the count rather than the listings: a shop confirming eight hundred
 * listings in one tap should not put eight hundred models through the queue
 * serialiser. Anything that needs the listings themselves reads the
 * ProductFreshnessChanged events fired alongside it.
 */
class StockConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Seller $seller,
        public int $listingCount,
        public bool $wasBulk,
        public ?User $actor = null,
    ) {}
}
