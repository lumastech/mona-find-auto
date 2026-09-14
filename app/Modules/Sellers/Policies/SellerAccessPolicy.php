<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Policies;

use App\Models\User;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;

/**
 * Who may look at and act on a selling business.
 *
 * The split that matters: a seller manages their own shop's policies, payout
 * accounts and documents; only MonaFind staff decide whether that shop is
 * verified, and only staff read the documents once they are uploaded.
 *
 * This one policy governs the seller's policies, payout accounts and
 * documents as well as the Seller row, because all of them answer the same
 * question — is this your business, or are you staff?
 */
class SellerAccessPolicy
{
    /**
     * The admin seller list.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::staffConsole());
    }

    /**
     * The admin detail screen. The public profile is not gated at all —
     * guests browse the whole storefront.
     */
    public function view(User $user, Seller $seller): bool
    {
        return $this->viewAny($user) || $this->owns($user, $seller);
    }

    /**
     * Edit the profile, publish policies, add payout accounts, upload
     * documents. The owner and their staff; nobody else, staff included —
     * MonaFind does not write a seller's refund policy for them.
     */
    public function manage(User $user, Seller $seller): bool
    {
        return $this->owns($user, $seller);
    }

    /**
     * Read the uploaded documents. Staff only: these hold NRC numbers and
     * company records, and the reviewer is the only person with a reason to
     * open them.
     */
    public function viewDocuments(User $user, Seller $seller): bool
    {
        return $user->hasAnyRole([Role::Moderator->value, Role::PlatformAdmin->value]) || $this->owns($user, $seller);
    }

    /**
     * Move the application through the workflow: review, inspect, verify,
     * reject.
     */
    public function verify(User $user, Seller $seller): bool
    {
        return $user->hasAnyRole([Role::Moderator->value, Role::PlatformAdmin->value]);
    }

    /**
     * Take a verified seller down, or put them back.
     */
    public function suspend(User $user, Seller $seller): bool
    {
        return $this->verify($user, $seller);
    }

    /**
     * Change the payment mode or the monetisation policy.
     *
     * Neither is anything a seller may touch: both decide how much of a
     * buyer's money reaches them and when. The Finance module wires the
     * screens; the gate lives here with the rest.
     */
    public function setCommercialTerms(User $user, Seller $seller): bool
    {
        return $user->hasAnyRole([Role::Finance->value, Role::PlatformAdmin->value]);
    }

    /**
     * The owner, or a member of staff on that business.
     */
    private function owns(User $user, Seller $seller): bool
    {
        return $user->getKey() === $seller->user_id;
    }
}
