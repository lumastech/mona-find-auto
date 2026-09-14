<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Support;

use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;

/**
 * One shop's worth of a cart.
 *
 * A MonaFind cart is grouped by seller everywhere it appears, because that is
 * what it actually is: a buyer fixing one car buys the filter from a parts
 * shop and the wing mirror from a breaker across town, and those are two
 * transactions with two dispatches and two sets of terms. Showing one
 * combined total would be describing a delivery nobody is going to make.
 */
final readonly class CartSellerGroup
{
    /**
     * @param  array<int, CartLine>  $lines
     */
    public function __construct(
        public Seller $seller,
        public array $lines,
    ) {}

    /**
     * What this shop's part of the cart comes to.
     *
     * Prices are VAT-inclusive, so this is what the buyer pays this seller
     * before any delivery charge — nothing here divides out tax.
     */
    public function subtotal(): Money
    {
        return array_reduce(
            $this->lines,
            static fn (Money $total, CartLine $line): Money => $total->plus($line->total()),
            Money::zero(),
        );
    }

    public function unitCount(): int
    {
        return array_sum(array_map(static fn (CartLine $line): int => $line->quantity, $this->lines));
    }

    public function hasIssues(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->hasIssues()) {
                return true;
            }
        }

        return false;
    }

    public function blocksCheckout(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->blocksCheckout()) {
                return true;
            }
        }

        return false;
    }
}
