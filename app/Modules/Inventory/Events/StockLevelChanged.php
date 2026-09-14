<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A shelf moved.
 *
 * Fired once per movement, after the locked transaction has committed —
 * listeners must never see a quantity that a rollback is about to undo.
 *
 * Everything that reacts to stock hangs off this one event rather than off
 * four narrower ones: the low-stock alert, the out-of-stock alert and the
 * back-in-stock notification are all the same question asked at different
 * quantities, and answering them in one place is what stops a variant that
 * goes 3 → 0 → 3 in a minute sending three contradictory emails.
 */
class StockLevelChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProductVariant $variant,
        public int $quantityBefore,
        public int $quantityAfter,
        public StockMovement $movement,
    ) {}

    /**
     * Whether this movement emptied the shelf.
     */
    public function ranOut(): bool
    {
        return $this->quantityBefore > 0 && $this->quantityAfter <= 0;
    }

    /**
     * Whether this movement refilled an empty shelf.
     *
     * This — not merely "quantity is positive" — is what a buyer's
     * "notify me when it is back" subscription waits for.
     */
    public function cameBackInStock(): bool
    {
        return $this->quantityBefore <= 0 && $this->quantityAfter > 0;
    }

    /**
     * Whether the seller has just dropped to their low-stock threshold.
     */
    public function fellToLowStock(): bool
    {
        $threshold = $this->variant->lowStockThreshold();

        return $this->quantityAfter > 0
            && $this->quantityAfter <= $threshold
            && $this->quantityBefore > $threshold;
    }
}
