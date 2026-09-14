<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Contracts\PaymentGateway;
use App\Modules\Orders\Enums\OrderGroupStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Orders\Support\OrderActor;
use Illuminate\Support\Facades\DB;

/**
 * The seam between a payment and the orders it paid for.
 *
 * Orders does not talk to Lenco and never will. What it owns is the other
 * half of the handshake: given a settled collection, put every order in the
 * group into Paid, freeze their terms, and start the seller's clock. Prompt
 * 09 brings the gateway calls, the webhook ingestion and the ledger entries,
 * and they all end up here.
 *
 * Two properties matter and both come from the state machine underneath.
 * It is idempotent — a webhook delivered twice finds the orders already Paid
 * and the transition refused, so stock comes down once and the terms freeze
 * once. And it is all-or-nothing per group: a buyer makes one payment, so
 * either every shop in that payment gets an order or none does.
 */
class OrderPaymentService
{
    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * The reference the next collection attempt on this group should carry.
     *
     * Built by the gateway rather than here so that the format
     * (MFA-{group}-{attempt}, restricted to [A-Za-z0-9._-]) has exactly one
     * implementation, and incremented first because Lenco will not take the
     * same reference twice — a buyer whose card is declined tries again.
     */
    public function beginAttempt(OrderGroup $group): string
    {
        $group->increment('payment_attempts');

        return $this->gateway->reference($group->public_id, $group->payment_attempts);
    }

    /**
     * The money arrived. Release every order in the group to its seller.
     *
     * Safe to call twice: orders already Paid are skipped rather than moved
     * again, which is what makes a retried webhook harmless.
     */
    public function settle(OrderGroup $group, ?string $reason = null): OrderGroup
    {
        DB::transaction(function () use ($group, $reason): void {
            $group->loadMissing('orders.items');

            foreach ($group->orders as $order) {
                if ($order->status->isPaid() && $order->paid_at !== null) {
                    continue;
                }

                $this->orders->markPaid($order, $reason);
            }

            if (! $group->status->isPaid()) {
                $group->forceFill([
                    'status' => OrderGroupStatus::Paid,
                    'paid_at' => now(),
                ])->save();
            }
        });

        return $group->refresh();
    }

    /**
     * The buyer walked away, or the attempt failed for good.
     *
     * Cancels only what is still unpaid — a group cannot be half-settled, but
     * a moderator cancelling a group after one order was already released
     * should not silently unwind a sale that is under way.
     */
    public function abandon(OrderGroup $group, ?string $reason = null): OrderGroup
    {
        DB::transaction(function () use ($group, $reason): void {
            $group->loadMissing('orders.items');

            foreach ($group->orders as $order) {
                if ($order->isPaid() || $order->status->isTerminal()) {
                    continue;
                }

                $this->orders->cancel($order, OrderActor::system(), $reason);
            }

            if ($group->status === OrderGroupStatus::PendingPayment) {
                $group->forceFill([
                    'status' => OrderGroupStatus::Cancelled,
                    'cancelled_at' => now(),
                ])->save();
            }
        });

        return $group->refresh();
    }

    /**
     * Whether every order in a group has reached the same paid state.
     *
     * A reconciliation check rather than a control-flow one: if this is ever
     * false on a group Lenco says it collected, something wrote an order
     * outside the state machine.
     */
    public function isFullySettled(OrderGroup $group): bool
    {
        return $group->orders()->get()->every(
            static fn (Order $order): bool => $order->paid_at !== null,
        );
    }
}
