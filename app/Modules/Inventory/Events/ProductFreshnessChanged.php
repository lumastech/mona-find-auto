<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A listing changed freshness state.
 *
 * Search reindexes on this, because the state carries a ranking multiplier
 * and a listing that has just gone Hidden must leave the index rather than
 * sink in it. Messaging tells the seller when the change is one they would
 * want to know about.
 *
 * Fired in both directions: sliding from Fresh to Ageing and jumping back to
 * Fresh on a confirmation are the same event with the two states swapped.
 */
class ProductFreshnessChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public FreshnessState $from,
        public FreshnessState $to,
    ) {}

    /**
     * Whether this change put the listing in front of buyers, or took it away.
     */
    public function changedBuyerVisibility(): bool
    {
        return $this->from->isVisibleToBuyers() !== $this->to->isVisibleToBuyers();
    }

    /**
     * Whether the listing got worse rather than better.
     */
    public function isDemotion(): bool
    {
        return $this->to->rankingMultiplier() < $this->from->rankingMultiplier();
    }
}
