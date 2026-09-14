<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Jobs;

use App\Modules\Shopping\Services\QuotationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Void quotes whose validity date has passed.
 *
 * The sweep does not cause the expiry — time did that, and both
 * Quotation::isAcceptable() and QuotationService::accept() already refuse a
 * stale quote without waiting to be told. What this job does is write it
 * down, so a seller's inbox and a buyer's list stop showing a price neither
 * of them can act on, and so a stale quote leaves the "still open" counts.
 *
 * Unique for the day, because two copies would each try to expire the same
 * rows; the service is idempotent, but the log line would lie about how many.
 */
class ExpireStaleQuotations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue(config('monafind.queues.default'));
    }

    public function uniqueId(): string
    {
        return 'quotations-expiry:'.now()->toDateString();
    }

    public function handle(QuotationService $quotations): void
    {
        $expired = $quotations->expireStale();

        if ($expired > 0) {
            Log::info('Stale quotations voided.', ['count' => $expired]);
        }
    }
}
