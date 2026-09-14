<?php

declare(strict_types=1);

namespace App\Modules\Search\Jobs;

use App\Modules\Search\Services\ListingIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The nightly full re-index.
 *
 * Two things drift no matter how carefully the event listeners are wired.
 * Quality scores go stale, because a rating that was recorded today changes
 * where a listing sits tomorrow and nothing about the listing itself
 * changed. And any index write that failed while Meilisearch was restarting
 * is simply lost — Scout has no reconciliation of its own.
 *
 * So once a night the whole catalogue is walked and rewritten. It is also the
 * only thing that recomputes every quality score against the current weights,
 * which is what makes an administrator's change to `ranking.weight.*` show up
 * everywhere rather than only on the listings that happened to be touched.
 */
class RebuildSearchIndex implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('search');
    }

    public function handle(ListingIndexer $indexer): void
    {
        $counts = $indexer->rebuild();

        Log::info('Search index rebuilt.', $counts);
    }
}
