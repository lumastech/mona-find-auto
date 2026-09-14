<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Catalog\Models\ProductVariant;
use RuntimeException;

/**
 * Somebody asked for more of something than the shelf holds.
 *
 * Thrown from inside the locked transaction, which is the whole point: by the
 * time this is raised the quantity has been read under a row lock, so it is
 * the true remaining figure and not a stale one. Checkout turns it into a
 * message naming the option and what is actually left, because "out of stock"
 * on a five-line order tells a buyer nothing about which line to change.
 */
class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly int $variantId,
        public readonly string $variantLabel,
        public readonly int $requested,
        public readonly int $available,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forVariant(ProductVariant $variant, int $requested): self
    {
        $label = $variant->name ?? $variant->sku;

        return new self($variant->getKey(), $label, $requested, $variant->quantity, sprintf(
            $variant->quantity === 0
                ? '%s is out of stock.'
                : 'Only %2$d of %1$s %3$s left, and %4$d were asked for.',
            $label,
            $variant->quantity,
            $variant->quantity === 1 ? 'is' : 'are',
            $requested,
        ));
    }
}
