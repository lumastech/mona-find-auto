<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Enums\PaymentSource;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CollectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The safety net under the webhook.
 *
 * Webhooks get lost. A deploy restarts the queue mid-delivery, a firewall
 * hiccups, Lenco has a bad minute — and the result is a buyer whose money
 * left their wallet and whose order still says "awaiting payment". This job
 * finds every attempt that has been pending too long and asks the gateway
 * directly.
 *
 * It is the reason an initiated attempt writes a pending row before the buyer
 * has touched anything: without that row there would be nothing to find.
 *
 * One failure does not stop the sweep. A single unparseable reference must not
 * leave forty other buyers unpaid, so each is tried on its own.
 */
class PollPendingPayments implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?int $olderThanMinutes = null) {}

    public function handle(CollectionService $collections): void
    {
        $minutes = $this->olderThanMinutes ?? (int) config('lenco.collections.stuck_after_minutes', 15);

        Payment::query()
            ->stuckPending($minutes)
            ->orderBy('id')
            ->chunkById(100, function ($payments) use ($collections): void {
                foreach ($payments as $payment) {
                    try {
                        $collections->verify($payment->reference, PaymentSource::Poll);
                    } catch (Throwable $exception) {
                        Log::warning('Could not poll pending payment.', [
                            'reference' => $payment->reference,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });
    }
}
