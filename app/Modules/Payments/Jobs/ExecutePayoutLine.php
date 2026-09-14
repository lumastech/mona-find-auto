<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\PayoutLine;
use App\Modules\Payments\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Send one seller their money.
 *
 * ## Tries once
 *
 * `$tries = 1`, which is unusual and deliberate. Every other job on the
 * platform retries; this one must not. A transfer that threw may still have
 * moved money, and a retry would move it again — so the service catches the
 * failure, marks the line unresolved and leaves it for Finance. There is no
 * safe automatic retry of an outbound payment, and pretending otherwise is
 * how sellers get paid twice.
 *
 * `ShouldBeUnique` on the line id closes the other door: two workers handed
 * the same line by a double-dispatched batch cannot both send it.
 */
class ExecutePayoutLine implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Long enough that a slow gateway does not look like a stuck job. */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $lineId) {}

    public function uniqueId(): string
    {
        return 'payout-line:'.$this->lineId;
    }

    public function handle(PayoutService $payouts): void
    {
        $line = PayoutLine::find($this->lineId);

        if ($line === null) {
            return;
        }

        $payouts->sendLine($line);
    }
}
