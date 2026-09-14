<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Models\User;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Staff changed a listing's Inspected/Uninspected badge.
 *
 * Search cares: an inspected listing scores higher in the ranking weights.
 */
class ListingInspectionChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public InspectionStatus $from,
        public InspectionStatus $to,
        public User $actor,
        public ?string $reason = null,
    ) {}
}
