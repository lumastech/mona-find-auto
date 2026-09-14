<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Models\User;
use App\Modules\Orders\Models\OrderDispute;
use App\Support\Roles\Role;

/**
 * A dispute is readable by the two parties and decidable only by MonaFind.
 *
 * The seller can see what they are accused of — they cannot defend themselves
 * otherwise — but resolution is staff-only and deliberately so: the whole
 * value of escrow to a buyer is that the person holding the part is not also
 * the person deciding whether to give the money back.
 */
class OrderDisputePolicy
{
    public function view(User $user, OrderDispute $dispute): bool
    {
        if ($user->hasRole(Role::staffConsole())) {
            return true;
        }

        $order = $dispute->order;

        return $order->belongsToBuyer($user) || $order->belongsToSellerOf($user);
    }

    /**
     * Picking a dispute up and deciding it. Staff only, and specifically not
     * the seller whose money it is.
     */
    public function resolve(User $user): bool
    {
        return $user->hasRole(Role::staffConsole());
    }
}
