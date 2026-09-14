<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Events;

use App\Modules\Shopping\Models\WishlistItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A buyer saved a listing.
 *
 * Fired on the save that actually happened — pressing an already-filled heart
 * does not fire it again, because nobody re-decided anything.
 */
class ListingWishlisted
{
    use Dispatchable, SerializesModels;

    public function __construct(public WishlistItem $item) {}
}
