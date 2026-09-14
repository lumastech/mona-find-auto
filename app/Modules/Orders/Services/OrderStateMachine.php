<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Actions\SnapshotOrderTerms;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderCompleted;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderStateChanged;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Exceptions\UnauthorisedOrderAction;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Support\OrderActor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that changes an order's status.
 *
 * Everything about a transition has to happen together: the legality check,
 * the actor check, the lifecycle timestamp, the deadline the next sweep will
 * run against, the immutable timeline row, and the events other modules act
 * on. Split any of those out and you get the failure this class exists to
 * prevent — an order that is Dispatched with no dispatched_at, or Completed
 * with nothing having told Payments to release the escrow.
 *
 * The rules themselves are not here. They are on OrderStatus, declared once,
 * so the seller portal, the API, the scheduled jobs and the tests are all
 * reading the same list rather than each holding an opinion.
 *
 * The row is re-read under lockForUpdate before anything is decided. Two
 * things genuinely do arrive at once on this platform — a seller pressing
 * "Dispatch" while the auto-cancel sweep is running, a buyer confirming
 * receipt as the auto-complete job fires — and without the lock both would
 * pass the legality check against the same stale status.
 */
class OrderStateMachine
{
    public function __construct(private readonly SnapshotOrderTerms $snapshotTerms) {}

    /**
     * Move an order, or refuse to.
     *
     * @param  array<string, mixed>  $context  Anything worth keeping about this move.
     *
     * @throws InvalidOrderTransition The lifecycle does not allow it.
     * @throws UnauthorisedOrderAction It does, but not by this party.
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        OrderActor $actor,
        ?string $reason = null,
        array $context = [],
    ): Order {
        [$order, $from] = DB::transaction(function () use ($order, $to, $actor, $reason, $context): array {
            /** @var Order $fresh */
            $fresh = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $from = $fresh->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidOrderTransition::between($fresh, $to);
            }

            if (! in_array($actor->type, $to->actorsAllowedToEnter(), true)) {
                throw UnauthorisedOrderAction::for($fresh, $to, $actor->type);
            }

            $at = now();

            $fresh->fill(['status' => $to, ...$this->stampsFor($fresh, $to, $at, $reason)]);

            if ($to === OrderStatus::Paid) {
                /*
                 * The terms freeze here and nowhere else. Done inside the
                 * same transaction as the status change so an order can never
                 * exist in the Paid state without the commission, payment
                 * mode and escrow window it will be settled under.
                 */
                $this->snapshotTerms->apply($fresh, $at);
            }

            $fresh->save();

            OrderStatusEvent::query()->create([
                'order_id' => $fresh->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actor->type,
                'actor_id' => $actor->userId(),
                'reason' => $reason,
                'context' => $context === [] ? null : $context,
                'created_at' => $at,
            ]);

            audit(
                $actor->user,
                'order.'.$to->value,
                $fresh,
                ['status' => $from->value],
                ['status' => $to->value],
                $reason,
                ['actor_type' => $actor->type->value, ...$context],
            );

            return [$fresh, $from];
        });

        $this->announce($order, $from, $to, $actor, $reason);

        return $order;
    }

    /**
     * The money arrived. Called by Payments when a collection settles.
     *
     * System-actored always: a payment is the gateway's word, and an order
     * that could be marked paid by a person is an order that could be marked
     * paid by the wrong person.
     */
    public function markPaid(Order $order, ?string $reason = null): Order
    {
        return $this->transition($order, OrderStatus::Paid, OrderActor::system(), $reason);
    }

    /**
     * The seller accepts the order.
     */
    public function confirm(Order $order, OrderActor $actor, ?string $reason = null): Order
    {
        return $this->transition($order, OrderStatus::SellerConfirmed, $actor, $reason);
    }

    /**
     * The seller has done their part: on the counter, or on its way.
     *
     * Which of the two it is comes from the order's own fulfilment method
     * rather than from the caller, so a "Mark ready" button cannot dispatch a
     * pickup order.
     */
    public function markReady(Order $order, OrderActor $actor, ?string $reason = null): Order
    {
        return $this->transition($order, $order->fulfilment_method->readyStatus(), $actor, $reason);
    }

    /**
     * The buyer has it. Starts the clock on their checking window.
     */
    public function markHandedOver(Order $order, OrderActor $actor, ?string $reason = null): Order
    {
        return $this->transition($order, $order->fulfilment_method->handoverStatus(), $actor, $reason);
    }

    /**
     * The buyer is satisfied, or the window closed with nobody objecting.
     */
    public function complete(Order $order, OrderActor $actor, ?string $reason = null): Order
    {
        return $this->transition($order, OrderStatus::Completed, $actor, $reason);
    }

    public function cancel(Order $order, OrderActor $actor, ?string $reason = null): Order
    {
        return $this->transition($order, OrderStatus::Cancelled, $actor, $reason);
    }

    public function dispute(Order $order, OrderActor $actor, ?string $reason = null): Order
    {
        return $this->transition($order, OrderStatus::Disputed, $actor, $reason);
    }

    public function refund(Order $order, OrderActor $actor, ?string $reason = null): Order
    {
        return $this->transition($order, OrderStatus::Refunded, $actor, $reason);
    }

    public function close(Order $order, OrderActor $actor, ?string $reason = null): Order
    {
        return $this->transition($order, OrderStatus::Closed, $actor, $reason);
    }

    /**
     * The lifecycle timestamp and any deadline this state sets.
     *
     * Deadlines are stored rather than computed on read for two reasons: the
     * sweeps become index scans instead of walks, and a window an
     * administrator lengthens tomorrow cannot retroactively move a deadline
     * an order is already running against.
     *
     * @return array<string, mixed>
     */
    private function stampsFor(Order $order, OrderStatus $to, CarbonInterface $at, ?string $reason): array
    {
        return match ($to) {
            OrderStatus::PendingPayment => [],

            OrderStatus::Paid => ['paid_at' => $at],

            OrderStatus::SellerConfirmed => [
                'confirmed_at' => $at,
                /* Answered in time; the auto-cancel sweep has nothing left to find. */
                'confirm_due_at' => null,
            ],

            OrderStatus::ReadyForPickup => ['ready_at' => $at],

            OrderStatus::Dispatched => ['dispatched_at' => $at, 'ready_at' => $order->ready_at ?? $at],

            OrderStatus::Collected, OrderStatus::Delivered => [
                'handed_over_at' => $at,
                'auto_complete_at' => $at->copy()->addDays($this->autoCompleteWindow($order)),
            ],

            OrderStatus::Completed => ['completed_at' => $at, 'auto_complete_at' => null],

            OrderStatus::Closed => ['closed_at' => $at],

            OrderStatus::Cancelled => [
                'cancelled_at' => $at,
                'cancellation_reason' => $reason,
                'confirm_due_at' => null,
                'auto_complete_at' => null,
            ],

            /*
             * A dispute holds the order where it is. auto_complete_at is
             * cleared rather than merely ignored so that a dispute resolved
             * back into Collected does not find a deadline that passed while
             * the argument was running and complete the order on the spot.
             */
            OrderStatus::Disputed => ['disputed_at' => $at, 'auto_complete_at' => null],

            OrderStatus::Refunded => ['refunded_at' => $at, 'auto_complete_at' => null],
        };
    }

    /**
     * How long this order's buyer gets to check the part.
     *
     * From the snapshot the order carries, falling back to today's setting
     * for an order that somehow reached handover without one — a
     * belt-and-braces path that only fires for data made outside checkout.
     */
    private function autoCompleteWindow(Order $order): int
    {
        return $order->auto_complete_window_days ?? $order->fulfilment_method->autoCompleteWindowDays();
    }

    /**
     * Tell the rest of the application, after the write has committed.
     *
     * OrderStateChanged goes out on every move; the specific events go out on
     * the three that other modules act on. Stock is the reason the paid and
     * cancelled events carry lines rather than an order id: Inventory has no
     * business loading an order to find out what to put back.
     */
    private function announce(Order $order, OrderStatus $from, OrderStatus $to, OrderActor $actor, ?string $reason): void
    {
        OrderStateChanged::dispatch($order, $from, $to, $actor->type, $actor->userId(), $reason);

        $order->loadMissing(['items', 'group']);

        match (true) {
            $to === OrderStatus::Paid => OrderPaid::dispatch(
                $order->getKey(),
                $order->paymentReference(),
                $order->stockLines(),
            ),

            $to === OrderStatus::Completed => OrderCompleted::dispatch($order, $actor->isSystem()),

            /*
             * Stock only goes back if it ever came down. An order cancelled
             * before payment never took any, and telling Inventory otherwise
             * would invent stock the seller never had.
             */
            ($to === OrderStatus::Cancelled || $to === OrderStatus::Refunded) && $from->isPaid() => OrderCancelled::dispatch(
                $order->getKey(),
                $order->paymentReference(),
                $order->stockLines(),
                $reason,
            ),

            default => null,
        };
    }
}
