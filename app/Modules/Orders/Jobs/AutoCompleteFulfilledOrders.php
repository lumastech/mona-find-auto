<?php

declare(strict_types=1);

namespace App\Modules\Orders\Jobs;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Buyers who never came back.
 *
 * Most buyers collect a part, fit it, and never open the app again. Without
 * this, every one of those orders would hold a seller's money forever, which
 * is not escrow — it is a platform that does not pay.
 *
 * Two things keep it honest. The deadline is the one snapshotted onto the
 * order at payment time, so escrow windows changed since cannot move it. And
 * an open dispute takes the order out of the query entirely — the scope
 * checks the disputes table rather than the order's status, because a
 * moderator may move an order back out of Disputed while still deciding and
 * the sweep must not slip in behind them.
 */
class AutoCompleteFulfilledOrders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        /* Money queue: both sweeps decide who keeps a payment. */
        $this->onQueue(config('monafind.queues.payments'));
    }

    private const BATCH = 200;

    /**
     * One sweep in flight at a time. Two overlapping runs would both read
     * the same overdue orders, and the second's transitions would be refused
     * by the lock — harmless, but it would fill the log with warnings that
     * look like a fault.
     */
    public function uniqueId(): string
    {
        return 'orders-auto-complete:'.now()->format('Y-m-d-H');
    }

    public function handle(OrderStateMachine $orders): void
    {
        Order::query()
            ->readyToAutoComplete()
            ->with(['items', 'group'])
            ->limit(self::BATCH)
            ->get()
            ->each(function (Order $order) use ($orders): void {
                try {
                    $orders->complete(
                        $order,
                        OrderActor::system(),
                        __('Completed automatically: the buyer did not report a problem in time.'),
                    );
                } catch (\Throwable $exception) {
                    Log::warning('Auto-complete skipped an order.', [
                        'order' => $order->number,
                        'reason' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
