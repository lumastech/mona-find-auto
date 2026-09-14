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
 * Sellers who never answered.
 *
 * A buyer whose money has been taken and whose seller has not so much as
 * acknowledged the order after a day is owed a decision, and the platform is
 * the one that has to make it. The order is cancelled, which restores the
 * stock the sale took, and the cancellation is what Payments later reads as a
 * refund request — an order cancelled after payment always has money to send
 * back.
 *
 * The window is per order, not global: `confirm_due_at` was written onto the
 * row at payment time from the setting in force then, so an administrator
 * lengthening the window today does not reprieve orders already past it, and
 * shortening it does not cancel a shop's whole inbox at once.
 */
class AutoCancelUnconfirmedOrders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        /* Money queue: both sweeps decide who keeps a payment. */
        $this->onQueue(config('monafind.queues.payments'));
    }

    /** Enough to clear a backlog without holding a worker all night. */
    private const BATCH = 200;

    /**
     * One sweep in flight at a time. Two overlapping runs would both read
     * the same overdue orders, and the second's transitions would be refused
     * by the lock — harmless, but it would fill the log with warnings that
     * look like a fault.
     */
    public function uniqueId(): string
    {
        return 'orders-auto-cancel:'.now()->format('Y-m-d-H');
    }

    public function handle(OrderStateMachine $orders): void
    {
        Order::query()
            ->awaitingSellerConfirmation()
            ->with(['items', 'group'])
            ->limit(self::BATCH)
            ->get()
            ->each(function (Order $order) use ($orders): void {
                try {
                    $orders->cancel(
                        $order,
                        OrderActor::system(),
                        __('The seller did not confirm this order in time.'),
                    );
                } catch (\Throwable $exception) {
                    /*
                     * One order that will not move must not stop the sweep.
                     * The likeliest cause is a seller confirming in the same
                     * second, which is a race the lock already decided
                     * correctly — the order is simply no longer eligible.
                     */
                    Log::warning('Auto-cancel skipped an order.', [
                        'order' => $order->number,
                        'reason' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
