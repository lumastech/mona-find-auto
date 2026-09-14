<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;

/**
 * One line of an order, reduced to what stock cares about.
 *
 * Orders and Inventory have to agree on something, and this is deliberately
 * the smallest possible something: which variant, and how many of it. Nothing
 * about price, buyer or delivery crosses this boundary — Inventory has no
 * business knowing any of it, and an event payload that carried it would tie
 * the two modules together far more tightly than they need to be.
 *
 * @see OrderPaid
 * @see OrderCancelled
 */
final readonly class OrderStockLine
{
    public function __construct(
        public int $variantId,
        public int $quantity,
    ) {}

    /**
     * Build a set of lines from a `[variantId => quantity]` map.
     *
     * @param  array<int, int>  $quantities
     * @return array<int, self>
     */
    public static function fromQuantities(array $quantities): array
    {
        $lines = [];

        foreach ($quantities as $variantId => $quantity) {
            $lines[] = new self((int) $variantId, (int) $quantity);
        }

        return $lines;
    }

    /**
     * @param  array<int, self>  $lines
     * @return array<int, int>
     */
    public static function toQuantities(array $lines): array
    {
        $quantities = [];

        foreach ($lines as $line) {
            $quantities[$line->variantId] = ($quantities[$line->variantId] ?? 0) + $line->quantity;
        }

        return $quantities;
    }
}
