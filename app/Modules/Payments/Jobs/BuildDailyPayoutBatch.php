<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Build the day's payout batch and leave it for Finance.
 *
 * The schedule prepares; it never approves and never sends. A nightly job
 * that could release money would defeat the dual control the whole payout
 * design rests on — so this ends with a batch awaiting a human, every time.
 *
 * Skips quietly when a batch is already open, so a re-run or an overlapping
 * schedule cannot produce two batches both claiming the same payables.
 */
class BuildDailyPayoutBatch implements ShouldQueue
{
    use Queueable;

    public function handle(PayoutService $payouts): void
    {
        if (PayoutBatch::query()->open()->exists()) {
            return;
        }

        $payouts->build();
    }
}
