<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Support\Roles\Role;

/**
 * Who may look at an order, and who may act on it.
 *
 * Seeing and doing are separated on purpose. A seller and a buyer both view
 * the same order and see different halves of it, but only one of them may
 * dispatch it and only the other may confirm receipt — and the guards on the
 * doing side live on OrderStatus, not here, so that the scheduled jobs and
 * the API are bound by them too. What this policy answers is the narrower
 * question the controllers ask first: is this order any of your business.
 */
class OrderPolicy
{
    /**
     * Staff see everything. Checked before every other rule so a moderator
     * working a dispute is never blocked by a buyer/seller test.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole(Role::staffConsole()) ? true : null;
    }

    public function view(User $user, Order $order): bool
    {
        return $order->belongsToBuyer($user) || $order->belongsToSellerOf($user);
    }

    /**
     * Confirming receipt, and raising a problem: the buyer's side.
     */
    public function act(User $user, Order $order): bool
    {
        return $order->belongsToBuyer($user);
    }

    /**
     * Confirming, marking ready, dispatching: the seller's side.
     */
    public function fulfil(User $user, Order $order): bool
    {
        return $order->belongsToSellerOf($user);
    }

    /**
     * The receipt is the buyer's document; the packing slip is the seller's.
     * Neither party gets the other's, because they say different things.
     */
    public function downloadReceipt(User $user, Order $order): bool
    {
        return $order->belongsToBuyer($user);
    }

    public function downloadPackingSlip(User $user, Order $order): bool
    {
        return $order->belongsToSellerOf($user);
    }
}
