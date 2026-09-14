<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\StockLevel;
use App\Modules\Inventory\Events\StockLevelChanged;
use App\Modules\Inventory\Notifications\LowStockAlert;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells a seller when a shelf is running out or has run out.
 *
 * Fires on the crossing, not on the level. A variant that falls to its
 * threshold is announced once and then stays quiet until it has climbed back
 * above the threshold — which is what the two `*_alerted_at` columns record.
 * Without them a shop selling briskly would get an email per sale, and the
 * one that mattered would be lost among them.
 */
class NotifySellerOfStockLevel implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(StockLevelChanged $event): void
    {
        $variant = $event->variant->loadMissing('product.seller.user');
        $level = $variant->stockLevel();

        match ($level) {
            StockLevel::OutOfStock => $this->alertOnce($variant, $level, 'out_of_stock_alerted_at'),
            StockLevel::LowStock => $this->alertOnce($variant, $level, 'low_stock_alerted_at'),
            StockLevel::InStock => $this->clearAlerts($variant),
        };
    }

    /**
     * Send an alert only if this shelf is not already sitting in that state.
     */
    private function alertOnce(ProductVariant $variant, StockLevel $level, string $column): void
    {
        if ($variant->{$column} !== null) {
            return;
        }

        $variant->forceFill([$column => now()])->save();

        $seller = $variant->product->seller;

        $seller->user->notify(new LowStockAlert($variant, $level));
    }

    /**
     * Back above the threshold: the next fall is worth announcing again.
     */
    private function clearAlerts(ProductVariant $variant): void
    {
        if ($variant->low_stock_alerted_at === null && $variant->out_of_stock_alerted_at === null) {
            return;
        }

        $variant->forceFill([
            'low_stock_alerted_at' => null,
            'out_of_stock_alerted_at' => null,
        ])->save();
    }
}
