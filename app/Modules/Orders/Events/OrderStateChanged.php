<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Every move, without exception.
 *
 * The specific events — OrderPaid, OrderCompleted, OrderCancelled — say what
 * happened to a listener that cares about one thing. This says that something
 * happened at all, and is what notifications, dispute-rate counting and any
 * future analytics hang off, so that adding a fourth interested party does
 * not mean adding a fourth event to the state machine.
 *
 * Fired after the transition has committed, so a listener reading the order
 * back sees the new state rather than racing the write that caused it.
 */
class OrderStateChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?OrderStatus $from,
        public OrderStatus $to,
        public OrderActorType $actorType,
        public ?int $actorId = null,
        public ?string $reason = null,
    ) {}
}
