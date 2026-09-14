<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Sellers\Models\Seller;

/**
 * The handful of numbers every seller-portal screen puts in front of a shop.
 *
 * One place, because the dashboard banner, the stock page header and the
 * mobile app all have to agree about what "needs confirming" means. Two
 * screens counting it slightly differently is how a seller ends up pressing
 * a button that does nothing.
 */
class StockSummary
{
    public function __construct(private readonly FreshnessService $freshness) {}

    /**
     * @return array{needs_confirmation: int, hidden: int, unconfirmed: int, ageing: int, fresh: int, low_stock: int, out_of_stock: int}
     */
    public function for(Seller $seller): array
    {
        $outstanding = $this->freshness->outstandingFor($seller);

        $variants = ProductVariant::query()->whereIn(
            'product_id',
            $this->freshness->sweepable()->select('id')->where('seller_id', $seller->getKey()),
        );

        return [
            /* Everything short of Fresh: what the "all stock accurate" button clears. */
            'needs_confirmation' => $outstanding[FreshnessState::Ageing->value]
                + $outstanding[FreshnessState::Unconfirmed->value]
                + $outstanding[FreshnessState::Hidden->value],
            'hidden' => $outstanding[FreshnessState::Hidden->value],
            'unconfirmed' => $outstanding[FreshnessState::Unconfirmed->value],
            'ageing' => $outstanding[FreshnessState::Ageing->value],
            'fresh' => $outstanding[FreshnessState::Fresh->value],
            'low_stock' => (clone $variants)->lowStock()->count(),
            'out_of_stock' => (clone $variants)->outOfStock()->count(),
        ];
    }
}
