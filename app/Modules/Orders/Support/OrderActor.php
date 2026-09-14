<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Models\User;
use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Models\Order;
use App\Support\Roles\Role;

/**
 * Who is trying to move an order, in the terms the state machine checks.
 *
 * The type is not taken on trust from the caller. `forUser()` works it out by
 * asking the order itself — is this the buyer, does this person own the
 * selling business, are they staff — because a controller that decided its
 * own actor type would be a controller that could decide wrongly, and the one
 * guard that matters most on this platform is the one stopping a seller
 * completing their own order and releasing their own escrow.
 */
final readonly class OrderActor
{
    private function __construct(
        public OrderActorType $type,
        public ?User $user = null,
    ) {}

    /**
     * The platform acting on its own: a timer, a webhook, a sweep.
     */
    public static function system(): self
    {
        return new self(OrderActorType::System);
    }

    /**
     * Work out what this person is TO THIS ORDER.
     *
     * Staff outrank the other two: a moderator who happens to have bought
     * something from a seller is still acting as staff when they resolve a
     * dispute, and a seller who is also a MonaFind employee does not get to
     * confirm their own order as a buyer.
     */
    public static function forUser(User $user, Order $order): self
    {
        if ($user->hasRole(Role::staffConsole())) {
            return new self(OrderActorType::Staff, $user);
        }

        if ($order->belongsToBuyer($user)) {
            return new self(OrderActorType::Buyer, $user);
        }

        if ($order->belongsToSellerOf($user)) {
            return new self(OrderActorType::Seller, $user);
        }

        /*
         * A stranger. Given no type they could act under rather than an
         * exception, so that the state machine's own guard is what refuses
         * them and the refusal is recorded in one place.
         */
        return new self(OrderActorType::Buyer, $user);
    }

    public function isSystem(): bool
    {
        return $this->type === OrderActorType::System;
    }

    public function isStaff(): bool
    {
        return $this->type === OrderActorType::Staff;
    }

    /**
     * The user id to record on the status event: null when nobody did it.
     */
    public function userId(): ?int
    {
        return $this->type->isPerson() ? $this->user?->getKey() : null;
    }
}
