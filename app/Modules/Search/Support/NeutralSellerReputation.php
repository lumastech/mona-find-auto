<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Search\Contracts\SellerReputationProvider;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Collection;

/**
 * The answer before Ratings and disputes exist: nobody has been rated, and
 * nothing has gone wrong.
 *
 * Deliberately not a null object that scores zero. Ranking every seller at
 * the bottom of the rating component is the same as ranking none of them, but
 * it would make the weights read as though reviews were being counted when
 * they are not — and it would leave the first shop to receive a review
 * vaulting the entire catalogue.
 */
final class NeutralSellerReputation implements SellerReputationProvider
{
    public function for(Seller $seller): SellerReputation
    {
        return SellerReputation::unrated();
    }

    /**
     * @param  Collection<int, Seller>  $sellers
     * @return array<int, SellerReputation>
     */
    public function forMany(Collection $sellers): array
    {
        return $sellers
            ->mapWithKeys(static fn (Seller $seller): array => [$seller->getKey() => SellerReputation::unrated()])
            ->all();
    }
}
