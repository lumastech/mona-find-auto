<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Sellers\Models\Seller;
use RuntimeException;

/**
 * The cart cannot become an order as it stands.
 *
 * Every one of these is something the buyer can act on, which is why they
 * carry sentences rather than codes: the checkout controller turns them
 * straight into what the page says. A cart that has gone stale between the
 * cart page and the payment button is the common case, and by far the most
 * important one to say out loud.
 */
class CheckoutUnavailable extends RuntimeException
{
    public static function emptyCart(): self
    {
        return new self('There is nothing in your cart.');
    }

    public static function cartHasBlockingIssues(): self
    {
        return new self('Something in your cart is no longer available. Check your cart before paying.');
    }

    public static function sellerDoesNotDeliver(Seller $seller): self
    {
        return new self(sprintf('%s does not deliver. Choose collection instead.', $seller->business_name));
    }

    public static function sellerDoesNotOfferPickup(Seller $seller): self
    {
        return new self(sprintf('%s does not offer collection. Choose delivery instead.', $seller->business_name));
    }

    public static function addressRequired(Seller $seller): self
    {
        return new self(sprintf('Choose a delivery address for your order from %s.', $seller->business_name));
    }

    public static function termsNotAccepted(Seller $seller): self
    {
        return new self(sprintf('You have to accept %s\'s terms before you can order from them.', $seller->business_name));
    }

    public static function missingSellerGroup(int $sellerId): self
    {
        return new self(sprintf('Your cart no longer contains anything from seller %d.', $sellerId));
    }

    public static function policiesChanged(Seller $seller): self
    {
        return new self(sprintf(
            '%s updated their terms while you were checking out. Please read them again.',
            $seller->business_name,
        ));
    }
}
