<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Support;

use App\Modules\Shopping\Enums\CartLineIssue;
use App\Modules\Shopping\Models\CartItem;
use App\Support\Money\Money;

/**
 * A cart line as it stands after being checked against the listing behind it.
 *
 * The row says what the buyer last agreed to; this says what is true now, and
 * what changed in between. Keeping them apart is what lets the cart show "was
 * K450, now K520" instead of either lying about the price or losing the fact
 * that it moved.
 *
 * @param  array<int, CartLineIssue>  $issues
 */
final readonly class CartLine
{
    /**
     * @param  array<int, CartLineIssue>  $issues
     */
    public function __construct(
        public CartItem $item,
        public Money $unitPrice,
        public int $quantity,
        public array $issues,
        public ?Money $previousUnitPrice = null,
        public ?int $requestedQuantity = null,
    ) {}

    public function total(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }

    public function hasIssues(): bool
    {
        return $this->issues !== [];
    }

    /**
     * Whether anything on this line stops the buyer paying.
     */
    public function blocksCheckout(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->blocksCheckout()) {
                return true;
            }
        }

        return false;
    }

    public function has(CartLineIssue $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }
}
