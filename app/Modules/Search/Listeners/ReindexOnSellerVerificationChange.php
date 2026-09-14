<?php

declare(strict_types=1);

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Jobs\ReindexSellerListings;
use App\Modules\Sellers\Events\SellerVerificationChanged;

/**
 * Verifying a shop is worth 20 points on every part it lists, and suspending
 * one has to take its whole catalogue out of the index.
 *
 * Both are the same job, because both are the same fact copied onto every
 * document the seller owns.
 */
class ReindexOnSellerVerificationChange
{
    public function handle(SellerVerificationChanged $event): void
    {
        ReindexSellerListings::dispatch($event->seller);
    }
}
