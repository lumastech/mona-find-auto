<?php

declare(strict_types=1);

namespace App\Modules\Search\Jobs;

use App\Modules\Search\Services\ListingIndexer;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-index everything a seller lists.
 *
 * The seller's name, type, town, GPS and verification are copied onto every
 * one of its documents so that the facet sidebar can filter on them without a
 * join. The price of that is this job: verify a shop, and its whole catalogue
 * has to be rewritten before "verified sellers only" tells the truth.
 */
class ReindexSellerListings implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Seller $seller)
    {
        $this->onQueue('search');
    }

    public function handle(ListingIndexer $indexer): void
    {
        $indexer->syncSeller($this->seller);
    }

    public function uniqueId(): string
    {
        return 'seller:'.$this->seller->getKey();
    }
}
