<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Support;

use App\Modules\Shopping\Enums\CartLineIssue;
use App\Modules\Shopping\Models\Cart;
use App\Support\Money\Money;

/**
 * A cart as the buyer should see it right now: grouped by shop, repriced
 * against the listings, and carrying whatever changed since they last looked.
 *
 * Produced by CartService::view() on every read. That is deliberate and it is
 * cheap — a cart is a handful of rows — and it means the cart cannot go stale
 * between a background job and a page load. The alternative, refreshing on a
 * schedule, guarantees that somebody eventually pays a price nobody showed
 * them.
 *
 * `removed` carries the lines that were dropped during the check, so the page
 * can say what went rather than just quietly having fewer rows than the buyer
 * remembers.
 */
final readonly class CartView
{
    /**
     * @param  array<int, CartSellerGroup>  $groups
     * @param  array<int, RemovedCartLine>  $removed
     */
    public function __construct(
        public Cart $cart,
        public array $groups,
        public array $removed = [],
    ) {}

    /**
     * The cart's whole value — the sum of the per-shop subtotals.
     *
     * Shown, but always beside the per-shop figures rather than instead of
     * them: it is what the buyer will spend, not what they will pay anybody.
     */
    public function total(): Money
    {
        return array_reduce(
            $this->groups,
            static fn (Money $total, CartSellerGroup $group): Money => $total->plus($group->subtotal()),
            Money::zero(),
        );
    }

    public function unitCount(): int
    {
        return array_sum(array_map(static fn (CartSellerGroup $group): int => $group->unitCount(), $this->groups));
    }

    public function lineCount(): int
    {
        return array_sum(array_map(static fn (CartSellerGroup $group): int => count($group->lines), $this->groups));
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    public function sellerCount(): int
    {
        return count($this->groups);
    }

    /**
     * Whether anything at all changed on this read.
     */
    public function hasChanges(): bool
    {
        if ($this->removed !== []) {
            return true;
        }

        foreach ($this->groups as $group) {
            if ($group->hasIssues()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the buyer can proceed to pay.
     *
     * A changed price does not stop them — they have now been shown it. An
     * empty shelf does.
     */
    public function blocksCheckout(): bool
    {
        foreach ($this->groups as $group) {
            if ($group->blocksCheckout()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every line in the cart, flattened out of its shop.
     *
     * @return array<int, CartLine>
     */
    public function lines(): array
    {
        return array_merge(...array_map(
            static fn (CartSellerGroup $group): array => $group->lines,
            $this->groups,
        )) ?: [];
    }

    /**
     * Every distinct thing that changed, for the notice at the top of the
     * page.
     *
     * @return array<int, CartLineIssue>
     */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->lines() as $line) {
            foreach ($line->issues as $issue) {
                $issues[$issue->value] = $issue;
            }
        }

        if ($this->removed !== []) {
            $issues[CartLineIssue::Unavailable->value] = CartLineIssue::Unavailable;
        }

        return array_values($issues);
    }
}
