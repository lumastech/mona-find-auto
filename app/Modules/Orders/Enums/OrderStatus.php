<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Modules\Orders\Services\OrderStateMachine;

/**
 * Where one seller's order stands.
 *
 * The states are declared here, once, together with the moves that are legal
 * from each and who is allowed to make them. That is deliberate: an order's
 * lifecycle is the thing on this platform that decides when a stranger's
 * money stops being theirs, and a lifecycle scattered across `if` statements
 * in a controller, a job and a policy is a lifecycle where those three
 * eventually disagree — usually about escrow, usually in a dispute.
 *
 * Note the shape of the middle of the machine. A pickup and a delivery split
 * after the seller confirms and rejoin at completion, because they are two
 * genuinely different promises: one ends when a buyer walks into a shop, the
 * other when a rider hands something over across town. They share an ending
 * because the money does not care which happened.
 *
 * @see OrderStateMachine  The only writer.
 */
enum OrderStatus: string
{
    /** Placed, not paid. Holds no stock and promises nothing. */
    case PendingPayment = 'pending_payment';

    /** The buyer's money has arrived. Stock is down; the seller owes an answer. */
    case Paid = 'paid';

    /** The seller has accepted the order and is getting the part ready. */
    case SellerConfirmed = 'seller_confirmed';

    /** Waiting on the counter for the buyer to collect. */
    case ReadyForPickup = 'ready_for_pickup';

    /** On its way to the buyer's address. */
    case Dispatched = 'dispatched';

    /** The buyer took it off the counter. */
    case Collected = 'collected';

    /** The courier handed it over. */
    case Delivered = 'delivered';

    /** The buyer is satisfied, or the confirmation window closed. Escrow releases. */
    case Completed = 'completed';

    /** Finished and settled. Nothing further happens to it. */
    case Closed = 'closed';

    /** Called off before fulfilment. Stock goes back. */
    case Cancelled = 'cancelled';

    /** The buyer has raised a problem. Auto-completion and escrow release stop. */
    case Disputed = 'disputed';

    /** The money went back to the buyer. */
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::SellerConfirmed => 'Confirmed by seller',
            self::ReadyForPickup => 'Ready for pickup',
            self::Dispatched => 'Dispatched',
            self::Collected => 'Collected',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
            self::Disputed => 'Disputed',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * What this state means to the buyer looking at it.
     */
    public function buyerDescription(): string
    {
        return match ($this) {
            self::PendingPayment => 'We are waiting for your payment to go through.',
            self::Paid => 'We have your payment. The seller has been asked to confirm the order.',
            self::SellerConfirmed => 'The seller has your order and is preparing it.',
            self::ReadyForPickup => 'Your part is on the counter. Bring your order number.',
            self::Dispatched => 'Your part is on its way.',
            self::Collected => 'You collected this order. Confirm it once you have checked the part.',
            self::Delivered => 'This order was delivered. Confirm it once you have checked the part.',
            self::Completed => 'Done. The seller has been paid.',
            self::Closed => 'This order is closed.',
            self::Cancelled => 'This order was cancelled.',
            self::Disputed => 'You raised a problem with this order. MonaFind is looking at it.',
            self::Refunded => 'This order was refunded.',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::PendingPayment => 'outline',
            self::Paid, self::SellerConfirmed, self::ReadyForPickup, self::Dispatched => 'default',
            self::Collected, self::Delivered, self::Completed, self::Closed => 'secondary',
            self::Cancelled, self::Disputed, self::Refunded => 'destructive',
        };
    }

    /**
     * The moves that are legal from here.
     *
     * A dispute is reachable from every state in which the buyer has paid and
     * the order has not finished, and from nowhere else — a buyer cannot
     * dispute an order they never paid for, and one already completed is
     * argued about through support rather than by reopening the machine.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::SellerConfirmed, self::Cancelled, self::Disputed],
            self::SellerConfirmed => [self::ReadyForPickup, self::Dispatched, self::Cancelled, self::Disputed],
            self::ReadyForPickup => [self::Collected, self::Cancelled, self::Disputed],
            self::Dispatched => [self::Delivered, self::Cancelled, self::Disputed],
            self::Collected, self::Delivered => [self::Completed, self::Disputed],
            self::Disputed => [self::Completed, self::Refunded, self::Cancelled],
            self::Completed, self::Refunded, self::Cancelled => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Who may move an order INTO this state.
     *
     * The brief's guards live here rather than in the controllers that
     * enforce them, so that the seller portal, the API, the scheduled jobs
     * and the tests are all reading the same rule. Two of them matter more
     * than the rest: only the owning seller confirms and dispatches, and only
     * the buyer — or the platform, when the window closes — completes. A
     * seller who could complete their own order could release their own
     * escrow.
     *
     * Staff are on every list because a moderator resolving a dispute has to
     * be able to put an order wherever the resolution says it belongs.
     *
     * @return array<int, OrderActorType>
     */
    public function actorsAllowedToEnter(): array
    {
        return match ($this) {
            /* Payment confirmation is the gateway's word, never a person's. */
            self::PendingPayment, self::Paid => [OrderActorType::System],

            self::SellerConfirmed, self::ReadyForPickup, self::Dispatched,
            self::Collected, self::Delivered => [OrderActorType::Seller, OrderActorType::Staff],

            self::Completed => [OrderActorType::Buyer, OrderActorType::System, OrderActorType::Staff],

            self::Disputed => [OrderActorType::Buyer, OrderActorType::Staff],

            self::Cancelled => [
                OrderActorType::Buyer,
                OrderActorType::Seller,
                OrderActorType::Staff,
                OrderActorType::System,
            ],

            self::Refunded => [OrderActorType::Staff, OrderActorType::System],

            self::Closed => [OrderActorType::System, OrderActorType::Staff],
        };
    }

    /**
     * Whether the order is finished with, one way or another.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the buyer's money has arrived and not gone back.
     *
     * What stock, escrow and the seller's payable all hang off.
     */
    public function isPaid(): bool
    {
        return ! in_array($this, [self::PendingPayment, self::Cancelled, self::Refunded], true);
    }

    /**
     * Whether the order is still moving towards fulfilment.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Closed, self::Cancelled, self::Refunded], true);
    }

    /**
     * Whether the buyer may still raise a problem.
     *
     * Before completion, as the brief puts it — once escrow has released
     * there is nothing left for a dispute to hold.
     */
    public function allowsDispute(): bool
    {
        return $this->canTransitionTo(self::Disputed);
    }

    /**
     * Whether the platform may complete this order on its own once the
     * confirmation window closes.
     */
    public function awaitsBuyerConfirmation(): bool
    {
        return in_array($this, [self::Collected, self::Delivered], true);
    }

    /**
     * Whether a seller still owes this order an answer.
     */
    public function awaitsSellerConfirmation(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Whether reaching this state puts the seller's stock back on the shelf.
     */
    public function restoresStock(): bool
    {
        return in_array($this, [self::Cancelled, self::Refunded], true);
    }

    /**
     * The states a seller's inbox is organised into, in the order they are
     * shown: what needs doing first, then what is waiting on somebody else.
     *
     * @return array<int, self>
     */
    public static function sellerInboxOrder(): array
    {
        return [
            self::Paid,
            self::SellerConfirmed,
            self::ReadyForPickup,
            self::Dispatched,
            self::Collected,
            self::Delivered,
            self::Disputed,
            self::Completed,
            self::Cancelled,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()),
        ));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
