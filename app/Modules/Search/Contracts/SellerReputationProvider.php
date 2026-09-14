<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

use App\Modules\Search\Support\SellerReputation;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Collection;

/**
 * Where a seller's rating, review count and dispute rate come from.
 *
 * Half the quality score is other people's experience of the seller, and that
 * history belongs to Ratings and to the disputes side of Orders — neither of
 * which Search may reach into. So Search states what it needs and those
 * modules bind an implementation when they arrive.
 *
 * Until then App\Modules\Search\Support\NeutralSellerReputation answers, and
 * every seller scores the same on these three components. That is the correct
 * behaviour rather than a placeholder: with no reviews anywhere, nobody
 * should be ranked above anybody else for having them.
 */
interface SellerReputationProvider
{
    public function for(Seller $seller): SellerReputation;

    /**
     * The same answer for many sellers at once, keyed by seller id.
     *
     * A full re-index asks about every seller in the catalogue, so this is
     * the method that decides whether a nightly rebuild issues one query or
     * fifty thousand.
     *
     * @param  Collection<int, Seller>  $sellers
     * @return array<int, SellerReputation>
     */
    public function forMany(Collection $sellers): array;
}
