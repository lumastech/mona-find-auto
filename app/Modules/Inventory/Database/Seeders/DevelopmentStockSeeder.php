<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Seeders;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Services\FreshnessService;
use Illuminate\Database\Seeder;

/**
 * Stock positions for local development.
 *
 * The point is to make every state visible at once. A developer opening the
 * seller portal should see a shop with something fresh, something ageing,
 * something labelled unconfirmed and something already hidden from buyers —
 * and on the storefront, a listing in stock beside one that is low and one
 * that has sold out. States nobody can see are states nobody notices are
 * broken.
 *
 * This only ever runs outside production; DatabaseSeeder guards the call.
 */
class DevelopmentStockSeeder extends Seeder
{
    public function __construct(private readonly FreshnessService $freshness) {}

    public function run(): void
    {
        $listings = Product::query()->with('variants')->orderBy('id')->get();

        if ($listings->isEmpty()) {
            return;
        }

        /* Days since confirmation, cycled so every freshness state appears. */
        $ages = [0, 1, 4, 7, 20];

        /* Quantities, cycled so In stock, Low stock and Out of stock all appear. */
        $quantities = [12, 2, 0, 5, 1];

        $listings->each(function (Product $product, int $index) use ($ages, $quantities): void {
            $days = $ages[$index % count($ages)];

            $product->forceFill([
                'freshness_confirmed_at' => now()->subDays($days)->startOfDay(),
                'freshness_state' => FreshnessState::forAge($days, $this->freshness->thresholds()),
                'freshness_hidden_at' => $days > 14 ? now()->subDays($days - 14) : null,
            ])->save();

            $product->variants->each(function (ProductVariant $variant, int $position) use ($quantities, $index): void {
                $variant->forceFill([
                    'quantity' => $quantities[($index + $position) % count($quantities)],
                ])->save();
            });
        });
    }
}
