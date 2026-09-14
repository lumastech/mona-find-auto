<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Shopping\Models\WishlistItem;
use App\Support\Money\Money;

/**
 * What has happened to a saved listing since the buyer saved it.
 *
 * The point of a wishlist on this platform is not the list — it is the news.
 * A buyer who saved a K3,200 turbo in March comes back because MonaFind can
 * tell them it is now K2,750, or that the last one went. Working that out
 * needs two facts that are true at different times, so it is a comparison
 * rather than a column, and it lives here rather than on either model.
 *
 * A drop is a drop and a rise is a rise: both are reported, because a buyer
 * deciding whether to move now is served by either. Only the drop is
 * celebrated on screen.
 */
final readonly class WishlistChange
{
    private function __construct(
        public ?Money $savedPrice,
        public ?Money $currentPrice,
        /** Negative when the price fell. Null when either price is unknown. */
        public ?Money $difference,
        public bool $wasInStock,
        public bool $isInStock,
    ) {}

    /**
     * Compare what was saved against what the listing says now.
     *
     * Needs the listing's variants loaded — both the current price and the
     * current stock position are read off them.
     */
    public static function between(WishlistItem $item, Product $product): self
    {
        $saved = $item->price_ngwee_at_save;
        $current = $product->fromPrice();

        return new self(
            savedPrice: $saved,
            currentPrice: $current,
            difference: $saved !== null && $current !== null ? $current->minus($saved) : null,
            wasInStock: $item->in_stock_at_save,
            isInStock: $product->hasStock(),
        );
    }

    /**
     * The listing is cheaper than when it was saved.
     */
    public function priceDropped(): bool
    {
        return $this->difference !== null && $this->difference->isNegative();
    }

    /**
     * The listing costs more than when it was saved.
     */
    public function priceRose(): bool
    {
        return $this->difference !== null && $this->difference->isPositive();
    }

    /**
     * How much cheaper or dearer, as a positive amount. Null when there is
     * nothing to compare.
     */
    public function priceDifferenceAmount(): ?Money
    {
        return $this->difference?->absolute();
    }

    /**
     * The shelf emptied while this sat on the wishlist.
     */
    public function wentOutOfStock(): bool
    {
        return $this->wasInStock && ! $this->isInStock;
    }

    /**
     * The seller restocked something the buyer had saved when it was gone.
     */
    public function cameBackInStock(): bool
    {
        return ! $this->wasInStock && $this->isInStock;
    }

    /**
     * Whether there is anything worth putting on the card at all.
     */
    public function isNoteworthy(): bool
    {
        return $this->priceDropped()
            || $this->priceRose()
            || $this->wentOutOfStock()
            || $this->cameBackInStock();
    }

    /**
     * @return array{
     *     saved_price_ngwee: int|null,
     *     current_price_ngwee: int|null,
     *     difference_ngwee: int|null,
     *     price_dropped: bool,
     *     price_rose: bool,
     *     went_out_of_stock: bool,
     *     came_back_in_stock: bool,
     *     in_stock: bool,
     *     noteworthy: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'saved_price_ngwee' => $this->savedPrice?->ngwee,
            'current_price_ngwee' => $this->currentPrice?->ngwee,
            'difference_ngwee' => $this->difference?->ngwee,
            'price_dropped' => $this->priceDropped(),
            'price_rose' => $this->priceRose(),
            'went_out_of_stock' => $this->wentOutOfStock(),
            'came_back_in_stock' => $this->cameBackInStock(),
            'in_stock' => $this->isInStock,
            'noteworthy' => $this->isNoteworthy(),
        ];
    }
}
