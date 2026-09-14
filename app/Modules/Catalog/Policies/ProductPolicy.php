<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Support\Roles\Role;

/**
 * Who may look at and act on a listing.
 *
 * Three separations matter here. A seller writes and submits their own
 * listings and nobody else's. A moderator decides whether a listing is
 * published and never edits its content — a moderator who could rewrite a
 * listing and then approve it is not reviewing anything. And the
 * Inspected badge is staff-only whatever else is true, because it is
 * MonaFind's claim about the part rather than the seller's.
 */
class ProductPolicy
{
    /**
     * The seller's own product list, or the admin moderation queue. The
     * storefront is not gated at all — guests browse everything.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Role::sellerPortal(), ...Role::staffConsole()]);
    }

    /**
     * Read a listing that is not published yet.
     */
    public function view(User $user, Product $product): bool
    {
        return $this->owns($user, $product) || $this->moderates($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(Role::sellerPortal()) && $user->ownsSeller();
    }

    /**
     * Edit the listing's content.
     *
     * The owner only, and only while the listing is not sitting in the
     * queue: a moderator reading one version and approving another is how a
     * bad listing gets published.
     */
    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product) && $product->status->isEditableBySeller();
    }

    /**
     * Send a listing for review, or pull it back out of the queue.
     */
    public function submit(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    /**
     * Retire a listing. The seller's own call; staff can too, because a
     * listing that should never have existed has to be removable.
     */
    public function archive(User $user, Product $product): bool
    {
        return $this->owns($user, $product) || $this->moderates($user);
    }

    /**
     * Publish, reject, or take a listing down.
     */
    public function moderate(User $user, Product $product): bool
    {
        return $this->moderates($user);
    }

    /**
     * Set the Inspected/Uninspected badge.
     *
     * Staff only, always. The badge says MonaFind looked at the part; a
     * seller who could set it would be making MonaFind's claim on its behalf.
     */
    public function inspect(User $user, Product $product): bool
    {
        return $this->moderates($user);
    }

    /**
     * The seller who owns the shop this listing belongs to.
     */
    private function owns(User $user, Product $product): bool
    {
        return $user->seller !== null && $user->seller->getKey() === $product->seller_id;
    }

    private function moderates(User $user): bool
    {
        return $user->hasAnyRole([Role::Moderator->value, Role::PlatformAdmin->value]);
    }
}
