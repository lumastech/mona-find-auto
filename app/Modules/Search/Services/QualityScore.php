<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Support\SellerReputation;

/**
 * One number per listing, worked out at index time, that decides the order
 * inside a relevance tier.
 *
 * Six things go into it and an administrator sets what each is worth
 * (`ranking.weight.*`): the seller's rating, how many people are behind that
 * rating, whether MonaFind verified the shop, whether MonaFind inspected the
 * part, how recently the stock was confirmed, and how often this seller's
 * orders end in a dispute. Nothing about distance and nothing about price —
 * price is the tiebreaker underneath this, applied by the index itself.
 *
 * It is computed here rather than in Meilisearch because the weights change:
 * an administrator moving "verified" from 20 to 40 has to be able to see the
 * catalogue reorder, which means a recompute, which means the arithmetic has
 * to live somewhere it can be re-run. Meilisearch only ever sorts on the
 * result.
 */
final class QualityScore
{
    /**
     * The rating a shop is assumed to deserve before anyone has rated it, and
     * how many reviews it takes to talk the platform out of that assumption.
     *
     * Without this, one delighted cousin leaving a single five-star review
     * outranks a shop with forty reviews averaging 4.8, which is both wrong
     * and the easiest ranking in the world to game.
     */
    private const PRIOR_RATING = 3.5;

    private const PRIOR_WEIGHT = 5.0;

    /** Ratings are out of five. */
    private const RATING_SCALE = 5.0;

    /**
     * The review count at which a shop has full marks for the review-count
     * component. Log-scaled below it, so the difference between 0 and 5
     * reviews counts for far more than the difference between 45 and 50.
     */
    private const REVIEW_SATURATION = 50;

    /**
     * The weight keys, in the order the brief lists them, with the defaults
     * SettingsSeeder ships.
     *
     * @var array<string, int>
     */
    public const WEIGHTS = [
        'seller_rating' => 30,
        'review_count' => 10,
        'verified_seller' => 20,
        'inspected' => 15,
        'freshness' => 15,
        'low_disputes' => 10,
    ];

    /**
     * The score for one listing. Higher is better; the scale is whatever the
     * weights add up to, 100 by default.
     */
    public function for(Product $product, SellerReputation $reputation, ?float $disputeThresholdPercent = null): float
    {
        $weights = $this->weights();
        $threshold = $disputeThresholdPercent ?? $this->disputeThresholdPercent();

        $score = $weights['seller_rating'] * $this->ratingFactor($reputation)
            + $weights['review_count'] * $this->reviewCountFactor($reputation->reviewCount)
            + $weights['verified_seller'] * ($product->seller->isVerified() ? 1.0 : 0.0)
            + $weights['inspected'] * ($product->isInspected() ? 1.0 : 0.0)
            /*
             * Inventory already decided what each freshness state is worth as
             * a fraction — reading it here keeps one answer to "how much does
             * neglecting your stock cost you" rather than two that drift.
             */
            + $weights['freshness'] * $product->freshness_state->rankingMultiplier()
            + $weights['low_disputes'] * $reputation->band($threshold)->qualityFactor();

        /*
         * Two decimals. Beyond that the differences are float noise, and ties
         * are wanted: they are what hands the decision to price descending.
         */
        return round($score, 2);
    }

    /**
     * A rating pulled towards the prior by how few people are behind it.
     */
    private function ratingFactor(SellerReputation $reputation): float
    {
        $count = max(0, $reputation->reviewCount);
        $average = $reputation->ratingAverage ?? self::PRIOR_RATING;

        $adjusted = (self::PRIOR_RATING * self::PRIOR_WEIGHT + $average * $count)
            / (self::PRIOR_WEIGHT + $count);

        return $this->clamp($adjusted / self::RATING_SCALE);
    }

    /**
     * Log-scaled, so a shop's first handful of reviews are worth the most.
     */
    private function reviewCountFactor(int $reviewCount): float
    {
        if ($reviewCount <= 0) {
            return 0.0;
        }

        return $this->clamp(log(1 + $reviewCount) / log(1 + self::REVIEW_SATURATION));
    }

    /**
     * The administrator's weights, falling back to the shipped defaults.
     *
     * @return array<string, int>
     */
    public function weights(): array
    {
        $weights = [];

        foreach (self::WEIGHTS as $key => $default) {
            $weights[$key] = max(0, (int) settings("ranking.weight.{$key}", $default));
        }

        return $weights;
    }

    /**
     * The highest score the current weights can produce.
     */
    public function maximum(): float
    {
        return (float) array_sum($this->weights());
    }

    /**
     * The dispute rate at which the platform already says a seller is in
     * trouble. Search does not get its own opinion about that number.
     */
    public function disputeThresholdPercent(): float
    {
        return (float) settings('risk.dispute_rate_threshold_percent', '2.00');
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
