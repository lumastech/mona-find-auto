<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Send one refund's money.
 *
 * Tries once, for the same reason ExecutePayoutLine does: a transfer that
 * threw may have moved money, and a retry would move it again. The service
 * parks an uncertain refund for Finance instead.
 */
class SendRefund implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $refundId) {}

    public function uniqueId(): string
    {
        return 'refund:'.$this->refundId;
    }

    public function handle(RefundService $refunds): void
    {
        $refund = Refund::find($this->refundId);

        if ($refund === null) {
            return;
        }

        $refunds->send($refund);
    }
}
