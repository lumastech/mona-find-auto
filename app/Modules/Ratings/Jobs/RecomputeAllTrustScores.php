<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Jobs;

use App\Modules\Ratings\Services\TrustScoreService;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The nightly rebuild of every seller's trust score.
 *
 * Two things drift no matter how carefully the listeners are wired. The
 * dispute rate is measured over a trailing window, so a seller's score
 * changes at midnight because an old dispute fell out of it and nothing
 * happened at all. And any recompute that failed while the queue was down is
 * simply lost.
 *
 * So the table is treated as a cache and rebuilt in full once a night. It
 * runs before the search index rebuild at 02:30, so the scores that rebuild
 * reads are the ones written minutes earlier rather than yesterday's.
 */
class RecomputeAllTrustScores implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue((string) config('monafind.queues.default'));
    }

    public function handle(TrustScoreService $scores): void
    {
        $count = 0;

        Seller::query()->chunkById(200, function ($sellers) use ($scores, &$count): void {
            foreach ($sellers as $seller) {
                $scores->recompute($seller);
                $count++;
            }
        });

        Log::info('Seller trust scores rebuilt.', ['sellers' => $count]);
    }
}
