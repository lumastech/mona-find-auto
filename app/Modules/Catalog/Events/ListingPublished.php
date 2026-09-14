<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A listing went live on the storefront.
 */
class ListingPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public ?User $actor = null,
    ) {}
}
