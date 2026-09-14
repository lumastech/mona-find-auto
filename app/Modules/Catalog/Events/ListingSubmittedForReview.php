<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A seller sent a listing to the moderation queue.
 */
class ListingSubmittedForReview
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public ?User $actor = null,
        /** True when the seller has already been turned down for this listing once. */
        public bool $isResubmission = false,
    ) {}
}
