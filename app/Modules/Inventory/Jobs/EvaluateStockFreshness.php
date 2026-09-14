<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Inventory\Services\FreshnessService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The daily sweep: recompute every listing's freshness state.
 *
 * Scheduled rather than derived on read, so that crossing a boundary is an
 * event with a time on it. Search reindexes off those events and the seller
 * is told; a state computed inside each query would produce neither, and
 * three different surfaces would each have their own idea of "now".
 *
 * Unique for the day it runs, because two sweeps racing would fire two sets
 * of transition events for the same crossings.
 */
class EvaluateStockFreshness implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Long enough for a large catalogue, short enough that a stuck run clears. */
    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue(config('monafind.queues.default'));
    }

    public function uniqueId(): string
    {
        return 'stock-freshness:'.now()->toDateString();
    }

    public function handle(FreshnessService $freshness): void
    {
        $counts = $freshness->refreshStates();

        Log::info('Stock freshness swept.', $counts);
    }
}
