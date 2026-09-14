<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Policies;

use App\Models\User;
use App\Modules\Shopping\Models\Quotation;
use App\Support\Roles\Role;

/**
 * Who may do what to a request for quotation.
 *
 * The rule the brief cares about is here: only the addressed seller may
 * quote. A price is a commitment to sell at it, and a shop that could be
 * committed by another shop's account is not a marketplace anybody would
 * trade on — so ownership is checked against the quotation's own seller_id
 * rather than against holding the seller role.
 *
 * The mirror rule matters as much: only the buyer who asked may accept. A
 * seller who could accept on the buyer's behalf could fill a stranger's cart.
 */
class QuotationPolicy
{
    /**
     * Either side may read their own.
     */
    public function view(User $user, Quotation $quotation): bool
    {
        return $this->isBuyer($user, $quotation) || $this->isAddressedSeller($user, $quotation);
    }

    /**
     * Answer with a price. The addressed shop, and nobody else.
     */
    public function quote(User $user, Quotation $quotation): bool
    {
        return $this->isAddressedSeller($user, $quotation)
            && $user->hasAnyRole(Role::sellerPortal())
            && $quotation->status->isOpen();
    }

    /**
     * Refuse to quote. Same authority as quoting.
     */
    public function decline(User $user, Quotation $quotation): bool
    {
        return $this->quote($user, $quotation);
    }

    /**
     * Take the price. The buyer who asked, and only while it stands.
     */
    public function accept(User $user, Quotation $quotation): bool
    {
        return $this->isBuyer($user, $quotation) && $quotation->isAcceptable();
    }

    /**
     * Withdraw a request the buyer no longer needs.
     */
    public function withdraw(User $user, Quotation $quotation): bool
    {
        return $this->isBuyer($user, $quotation) && $quotation->status->isOpen();
    }

    private function isBuyer(User $user, Quotation $quotation): bool
    {
        return $user->getKey() === $quotation->user_id;
    }

    /**
     * Whether this account runs the shop the request was addressed to.
     *
     * Deliberately not "runs a shop": a request belongs to one seller, and
     * every other seller on the platform is a stranger to it.
     */
    private function isAddressedSeller(User $user, Quotation $quotation): bool
    {
        return $user->seller !== null && $user->seller->getKey() === $quotation->seller_id;
    }
}
