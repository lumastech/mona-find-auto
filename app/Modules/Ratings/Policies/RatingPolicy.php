<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Policies;

use App\Models\User;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;

/**
 * Who may read a rating, answer it, and object to it.
 *
 * The rule that does the work is `view`. Buyer-directed ratings — a shop's
 * rating of a buyer, a mechanic's rating of a buyer — are trade information
 * between the people who have to decide whether to take an order. They are
 * not shown to the public and they are not shown to the buyer either: a buyer
 * who could read what shops say about them would learn to threaten a bad
 * review over it, and the ratings would stop being honest within a month.
 *
 * The policy is not the only enforcement. Every list query filters on
 * direction before a policy is ever consulted — see Rating::scopePublic() —
 * because a policy protects a page and a scope protects a query, and a
 * private rating leaking through a JSON list is the failure that matters.
 */
class RatingPolicy
{
    /**
     * Staff see everything: moderation is not possible otherwise.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole(Role::staffConsole()) ? true : null;
    }

    public function view(?User $user, Rating $rating): bool
    {
        if ($rating->direction->isPublic()) {
            return $rating->status->isVisible();
        }

        if ($user === null) {
            return false;
        }

        /* Its author, and anyone who trades here and may have to deal with this buyer. */
        return $this->wrote($user, $rating) || $this->tradesHere($user);
    }

    /**
     * Whether this person may leave the reply — the party the review is
     * about, and only if nobody has replied yet.
     */
    public function reply(User $user, Rating $rating): bool
    {
        if (! $rating->direction->allowsReply() || $rating->hasReply() || ! $rating->status->isVisible()) {
            return false;
        }

        return $this->isRatee($user, $rating);
    }

    /**
     * Anyone signed in may object to a review they can see, except its
     * author — somebody who regrets what they wrote needs support, not a
     * report queue.
     */
    public function report(User $user, Rating $rating): bool
    {
        return $this->view($user, $rating) && ! $this->wrote($user, $rating);
    }

    public function moderate(User $user): bool
    {
        /* Reached only for non-staff, who never moderate. before() lets staff through. */
        return false;
    }

    private function wrote(User $user, Rating $rating): bool
    {
        return $rating->submitted_by === $user->getKey();
    }

    /**
     * Whether the rating is about this person, or about the shop they run.
     */
    private function isRatee(User $user, Rating $rating): bool
    {
        if ($rating->isRatee($user)) {
            return true;
        }

        $seller = $user->seller;

        return $seller instanceof Seller && $rating->isRatee($seller);
    }

    private function tradesHere(User $user): bool
    {
        return $user->hasAnyRole([...Role::sellerPortal(), Role::Mechanic->value]);
    }
}
