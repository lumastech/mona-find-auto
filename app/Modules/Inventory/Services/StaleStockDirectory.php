<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The shops that have stopped confirming their stock.
 *
 * Counted by SELLER rather than by listing, because that is the unit of the
 * conversation somebody is about to have: a yard with 300 unconfirmed parts
 * is one phone call, not three hundred problems. A listing-level view already
 * exists in the seller's own portal.
 *
 * Queried from the listing side rather than through a relation on Seller,
 * because Sellers has no relation to a listing and should not grow one — a
 * shop is a business, and that it happens to have parts filed against it is
 * Catalog's fact about it, not part of what a seller is.
 */
class StaleStockDirectory
{
    /**
     * Sellers with at least one listing past the confirmation window, worst
     * first.
     *
     * @return LengthAwarePaginator<int, Seller>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return Seller::query()
            ->whereIn('id', $this->staleSellerIds())
            ->withAggregate('city', 'name')
            ->addSelect([
                'stale_listings_count' => $this->countForSeller(),
                'hidden_listings_count' => $this->countForSeller(FreshnessState::Hidden),
            ])
            ->orderByDesc('stale_listings_count')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function sellerCount(): int
    {
        return Seller::query()->whereIn('id', $this->staleSellerIds())->count();
    }

    /**
     * The ids of every shop with stale stock, as a subquery.
     *
     * @return Builder<Product>
     */
    private function staleSellerIds(): Builder
    {
        return $this->staleListings()->select('seller_id');
    }

    /**
     * A correlated count of one shop's stale listings.
     *
     * @return Builder<Product>
     */
    private function countForSeller(?FreshnessState $state = null): Builder
    {
        return $this->staleListings($state)
            ->selectRaw('count(*)')
            ->whereColumn('products.seller_id', 'sellers.id');
    }

    /**
     * Listings whose clock has run past Ageing.
     *
     * Ageing is deliberately excluded: it is a small ranking nudge the seller
     * is being reminded about, not yet a reason for staff to intervene.
     *
     * @return Builder<Product>
     */
    private function staleListings(?FreshnessState $state = null): Builder
    {
        return Product::query()
            ->whereIn('status', FreshnessService::sweepableStatuses())
            ->whereIn('freshness_state', $state instanceof FreshnessState ? [$state] : [
                FreshnessState::Unconfirmed,
                FreshnessState::Hidden,
            ]);
    }
}
