<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Modules\Ratings\Models\SellerTrustScore;
use App\Modules\Search\Contracts\SellerReputationProvider;
use App\Modules\Search\Support\SellerReputation;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Collection;

/**
 * The real answer to Search's question, now that ratings exist.
 *
 * Search declared SellerReputationProvider and shipped a neutral
 * implementation so that ranking worked before anybody had been reviewed.
 * This replaces it, and the replacement is the whole of the coupling: Search
 * still knows nothing about ratings, and Ratings still knows nothing about
 * how a quality score is computed.
 *
 * Everything is read from the precomputed seller_trust_scores row rather than
 * aggregated live, because forMany() is called by the nightly re-index with
 * every seller in the catalogue. A seller with no row yet is genuinely
 * unrated — treating that as zero stars would rank a brand-new shop below one
 * with a page of complaints.
 */
class RatingsSellerReputation implements SellerReputationProvider
{
    public function for(Seller $seller): SellerReputation
    {
        $score = SellerTrustScore::query()->firstWhere('seller_id', $seller->getKey());

        return $this->toReputation($score);
    }

    /**
     * @param  Collection<int, Seller>  $sellers
     * @return array<int, SellerReputation>
     */
    public function forMany(Collection $sellers): array
    {
        $ids = $sellers->map(static fn (Seller $seller): int => (int) $seller->getKey())->all();

        /** @var Collection<int, SellerTrustScore> $scores */
        $scores = SellerTrustScore::query()
            ->whereIn('seller_id', $ids)
            ->get()
            ->keyBy('seller_id');

        $reputations = [];

        foreach ($ids as $id) {
            $reputations[$id] = $this->toReputation($scores->get($id));
        }

        return $reputations;
    }

    private function toReputation(?SellerTrustScore $score): SellerReputation
    {
        if ($score === null || $score->ratings_count === 0) {
            /*
             * No reviews. The dispute rate is still worth carrying — a shop
             * can have fallen out with buyers without any of them writing
             * anything — so it is read where a row exists.
             */
            return new SellerReputation(
                ratingAverage: null,
                reviewCount: 0,
                disputeRatePercent: $score === null ? 0.0 : $score->dispute_rate_percent,
            );
        }

        return new SellerReputation(
            ratingAverage: $score->average_stars,
            reviewCount: $score->ratings_count,
            disputeRatePercent: $score->dispute_rate_percent,
        );
    }
}
