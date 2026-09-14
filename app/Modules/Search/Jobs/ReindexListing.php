<?php

declare(strict_types=1);

namespace App\Modules\Search\Jobs;

use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Services\ListingIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bring one listing's index entry back in step with the database.
 *
 * Queued because Meilisearch is a network call and the events that trigger it
 * — a moderator marking a part Inspected, the nightly freshness sweep — are
 * either somebody waiting on a response or a loop over thousands of rows.
 */
class ReindexListing implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Product $product)
    {
        $this->onQueue('search');
    }

    public function handle(ListingIndexer $indexer): void
    {
        $indexer->sync($this->product);
    }

    /**
     * One re-index per listing in flight is enough; the last one wins anyway.
     */
    public function uniqueId(): string
    {
        return 'listing:'.$this->product->getKey();
    }
}
