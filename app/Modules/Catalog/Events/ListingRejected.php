<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A moderator turned a listing down.
 *
 * The per-field reasons travel with the event because that is what the seller
 * is told — a notification that only says "rejected" makes them guess.
 */
class ListingRejected
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, string>  $fieldReasons  Keyed by form field.
     */
    public function __construct(
        public Product $product,
        public string $reason,
        public array $fieldReasons = [],
        public ?User $actor = null,
    ) {}
}
