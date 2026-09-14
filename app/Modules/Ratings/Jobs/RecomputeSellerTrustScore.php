<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Jobs;

use App\Modules\Ratings\Services\TrustScoreService;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bring one seller's trust score back in step with their reviews and
 * disputes.
 *
 * Queued because a new review should not make the buyer wait on an aggregate
 * over every order the shop has ever taken, and unique because a busy shop
 * collecting three reviews in a minute needs one recompute, not three — the
 * last one would produce the same answer as all of them.
 */
class RecomputeSellerTrustScore implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Seller $seller)
    {
        $this->onQueue((string) config('monafind.queues.default'));
    }

    public function handle(TrustScoreService $scores): void
    {
        $scores->recompute($this->seller);
    }

    public function uniqueId(): string
    {
        return 'seller-trust:'.$this->seller->getKey();
    }
}
