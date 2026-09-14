<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Models\User;
use App\Modules\Inventory\Enums\StockImportStatus;
use App\Modules\Inventory\Exceptions\StockImportNotApplicable;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Modules\Inventory\Services\StockImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes a confirmed bulk upload to the shelves.
 *
 * Queued because a two-thousand-row apply is two thousand locked
 * transactions, and a seller holding a browser tab open for it would give up
 * and press the button again.
 *
 * Not retried. Every row goes through the stock ledger, so a half-applied
 * batch has already written real movements — replaying it from the top would
 * re-apply rows that succeeded. A failure leaves the batch marked Failed with
 * the reason on it, and the seller uploads again.
 */
class ApplyStockImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly StockImportBatch $batch,
        private readonly ?User $actor = null,
    ) {
        $this->onQueue(config('monafind.queues.default'));
    }

    public function handle(StockImportService $imports): void
    {
        try {
            $imports->apply($this->batch, $this->actor);
        } catch (StockImportNotApplicable) {
            /* Somebody applied or discarded it first. Nothing to do and nothing wrong. */
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->batch->forceFill([
            'status' => StockImportStatus::Failed,
            'failure_reason' => $exception->getMessage(),
        ])->save();
    }
}
