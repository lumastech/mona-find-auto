<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Jobs;

use App\Modules\Privacy\Enums\ErasureStatus;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Services\AccountEraser;
use App\Modules\Privacy\Services\ErasureGuard;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Erases the accounts whose grace period has run out.
 *
 * Runs nightly. Small batches, because each request is a transaction across a
 * dozen tables and a sweep that tried to do four hundred of them at once
 * would hold locks on `users` for as long as it took.
 *
 * ## Why it re-checks before erasing
 *
 * A request can become un-erasable between being made and coming due — the
 * person places a last order, a dispute is opened against them, a payout is
 * still in flight. `ErasureGuard` asks every module whether it is ready, and
 * a "no" moves the request to Blocked with a reason rather than erasing an
 * account in the middle of a transaction someone else is party to.
 *
 * ## One failure does not stop the sweep
 *
 * Each request is caught individually. One account whose erasure throws
 * should not leave the other nine waiting another day.
 */
class ProcessDueErasures implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    private const BATCH = 25;

    public function __construct()
    {
        $this->onQueue(config('monafind.queues.default'));
    }

    public function uniqueId(): string
    {
        return 'privacy-erasures:'.now()->format('Y-m-d-H');
    }

    public function handle(AccountEraser $eraser, ErasureGuard $guard): void
    {
        ErasureRequest::query()
            ->due()
            ->with('user')
            ->limit(self::BATCH)
            ->get()
            ->each(function (ErasureRequest $request) use ($eraser, $guard): void {
                try {
                    $blocker = $guard->blockerFor($request->user);

                    if ($blocker !== null) {
                        $request->forceFill([
                            'status' => ErasureStatus::Blocked,
                            'blocked_reason' => $blocker,
                        ])->save();

                        audit(null, 'privacy.erasure.blocked', $request, null, ['reason' => $blocker], $blocker);

                        return;
                    }

                    $eraser->complete($request);
                } catch (Throwable $exception) {
                    Log::error('An account erasure failed.', [
                        'erasure_request_id' => $request->getKey(),
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
