<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Enums;

/**
 * What re-validating a cart line found wrong with it.
 *
 * A MonaFind cart can sit for days between the buyer adding a part and paying
 * for it, and in that time the seller may have raised the price, sold the
 * last one, or had the listing taken down. Checkout is the wrong place to
 * discover any of that, so the cart says it on sight — every time the cart is
 * read, not on a schedule.
 *
 * The distinction that matters is between a line that changed and a line that
 * is gone. A price rise or a clamped quantity is still a line the buyer can
 * pay for once they have seen what it now says; an unavailable listing is
 * not, and is dropped rather than left for the payment page to refuse.
 */
enum CartLineIssue: string
{
    /** The seller's price moved since this went in the cart. */
    case PriceChanged = 'price_changed';

    /** The shelf no longer holds what was asked for; the line was clamped. */
    case QuantityReduced = 'quantity_reduced';

    /** The shelf is empty. The line stays so the buyer can decide. */
    case OutOfStock = 'out_of_stock';

    /** Unpublished, hidden or from a shop that is down. The line is removed. */
    case Unavailable = 'unavailable';

    /** The accepted quote behind this line passed its validity date. */
    case QuoteExpired = 'quote_expired';

    public function label(): string
    {
        return match ($this) {
            self::PriceChanged => 'Price changed',
            self::QuantityReduced => 'Quantity reduced',
            self::OutOfStock => 'Out of stock',
            self::Unavailable => 'No longer available',
            self::QuoteExpired => 'Quote expired',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PriceChanged => 'The seller changed this price since you added it. The cart shows the current price.',
            self::QuantityReduced => 'The seller does not have as many as you asked for, so the quantity has been reduced.',
            self::OutOfStock => 'The seller has run out of this part. Remove it to check out.',
            self::Unavailable => 'This listing is no longer on sale and has been removed from your cart.',
            self::QuoteExpired => 'The quoted price passed its validity date, so this line is back at the seller\'s current price.',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::PriceChanged, self::QuantityReduced => 'outline',
            self::OutOfStock, self::Unavailable, self::QuoteExpired => 'destructive',
        };
    }

    /**
     * Whether a cart carrying this issue may still go to checkout.
     *
     * A price the buyer has now been shown is payable; an empty shelf is not.
     */
    public function blocksCheckout(): bool
    {
        return match ($this) {
            self::PriceChanged, self::QuantityReduced, self::QuoteExpired => false,
            self::OutOfStock, self::Unavailable => true,
        };
    }
}
