<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Exceptions;

use App\Modules\Catalog\Models\Product;
use RuntimeException;

/**
 * Something a buyer cannot put in a cart.
 *
 * Kept apart from a validation failure on purpose: the buyer filled the form
 * in correctly and the world changed underneath them, so the controllers turn
 * this into a message about the listing rather than an error on a field.
 */
class ListingNotPurchasable extends RuntimeException
{
    public static function unavailable(Product $product): self
    {
        return new self(sprintf('"%s" is no longer on sale.', $product->name));
    }

    public static function outOfStock(Product $product): self
    {
        return new self(sprintf('"%s" is out of stock.', $product->name));
    }
}
