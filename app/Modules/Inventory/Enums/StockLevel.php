<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * What a buyer is told about availability.
 *
 * Deliberately three coarse states rather than a number. "2 left" on a shop
 * whose counter staff sold one this morning is a more confident claim than
 * the platform can honestly make; "Low stock" is true either way, and it is
 * the nudge that actually matters to somebody deciding whether to travel.
 */
enum StockLevel: string
{
    case InStock = 'in_stock';
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'In stock',
            self::LowStock => 'Low stock',
            self::OutOfStock => 'Out of stock',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::InStock => 'secondary',
            self::LowStock => 'outline',
            self::OutOfStock => 'destructive',
        };
    }

    public function isAvailable(): bool
    {
        return $this !== self::OutOfStock;
    }

    /**
     * The level a quantity sits at, given the variant's own low-stock
     * threshold.
     */
    public static function forQuantity(int $quantity, int $lowStockThreshold): self
    {
        return match (true) {
            $quantity <= 0 => self::OutOfStock,
            $quantity <= $lowStockThreshold => self::LowStock,
            default => self::InStock,
        };
    }
}
