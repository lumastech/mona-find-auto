<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Models\User;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A listing moved through its lifecycle.
 *
 * Search reindexes on this, Messaging tells the seller, and the storefront
 * caches drop what they were holding. Every move fires it — including the
 * ones the platform makes on its own, which is when the actor is null.
 */
class ListingStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public ListingStatus $from,
        public ListingStatus $to,
        public ?string $reason = null,
        public ?User $actor = null,
    ) {}

    /**
     * Whether this move put the listing in front of buyers, or took it away.
     */
    public function changedBuyerVisibility(): bool
    {
        return $this->from->isVisibleToBuyers() !== $this->to->isVisibleToBuyers();
    }
}
