<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DisputeService;
use App\Modules\Ratings\Enums\TrustBand;
use App\Modules\Ratings\Events\SellerTrustScoreChanged;
use App\Modules\Ratings\Models\SellerTrustScore;
use App\Modules\Ratings\Support\RatingAggregate;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * One number per seller for how much the platform trusts them.
 *
 * Four things go into it and an administrator sets what each is worth
 * (`ratings.trust.weight.*`): what buyers scored them, how many buyers are
 * behind that score, how often their orders end in a dispute, and how often
 * an order they took actually completed. It is the same shape as the search
 * quality score on purpose — weights in settings, arithmetic in PHP,
 * recomputed rather than derived on read — because both answer versions of
 * the same question and staff should not have to learn two mental models.
 *
 * What it is NOT is the search ranking. Search has its own quality score with
 * its own weights; this feeds it two of its six components through
 * SellerReputationProvider and otherwise stays out of it. Two scores rather
 * than one because they are read by different people for different decisions:
 * ranking decides which shop a buyer sees first, and trust decides which shop
 * a moderator has a conversation with.
 *
 * The rating component is pulled towards a prior by how few reviews are
 * behind it, so a shop with one delighted review does not outrank forty
 * reviews averaging 4.8 — and so a new shop is not condemned by its first
 * unhappy customer.
 */
class TrustScoreService
{
    /** The score a shop is assumed to deserve before anyone has rated it. */
    private const PRIOR_RATING = 3.5;

    /** How many reviews it takes to talk the platform out of that assumption. */
    private const PRIOR_WEIGHT = 5.0;

    private const RATING_SCALE = 5.0;

    /** The review count at which the volume component is full marks. */
    private const REVIEW_SATURATION = 50;

    /**
     * The weight keys with the defaults SettingsSeeder ships.
     *
     * @var array<string, int>
     */
    public const WEIGHTS = [
        'rating' => 50,
        'volume' => 15,
        'low_disputes' => 25,
        'fulfilment' => 10,
    ];

    public function __construct(
        private readonly DisputeService $disputes,
        private readonly RatingService $ratings,
    ) {}

    /**
     * Rebuild one seller's row and announce it if anything ranking reads has
     * moved.
     */
    public function recompute(Seller $seller): SellerTrustScore
    {
        $aggregate = $this->aggregateFor($seller);
        $disputeRate = $this->disputes->disputeRateFor($seller->getKey(), $this->trailingDays());
        $completed = $this->completedOrderCount($seller);

        $score = $this->score($aggregate, $disputeRate, $seller, $completed);

        $existing = SellerTrustScore::query()->firstWhere('seller_id', $seller->getKey());
        $before = $this->rankingComponents($existing);

        $row = SellerTrustScore::query()->updateOrCreate(
            ['seller_id' => $seller->getKey()],
            [
                'ratings_count' => $aggregate->count,
                'average_stars' => $aggregate->average,
                'star_breakdown' => $aggregate->breakdown,
                'dispute_rate_percent' => round($disputeRate, 2),
                'completed_orders' => $completed,
                'trust_score' => $score,
                'trust_band' => $this->band($score, $aggregate),
                'computed_at' => now(),
            ],
        );

        /*
         * Re-indexing a catalogue to write identical documents is the kind of
         * work that looks free until a shop has four thousand parts, so the
         * event only fires when one of the three things Search actually reads
         * has changed.
         */
        if ($before !== $this->rankingComponents($row)) {
            SellerTrustScoreChanged::dispatch($seller, $row);
        }

        return $row;
    }

    /**
     * The public star breakdown for a seller.
     */
    public function aggregateFor(Seller $seller): RatingAggregate
    {
        return $this->ratings->aggregateFor($seller);
    }

    /**
     * The stored row, or a freshly computed one if the seller has never had
     * a score written.
     */
    public function for(Seller $seller): SellerTrustScore
    {
        return SellerTrustScore::query()->firstWhere('seller_id', $seller->getKey())
            ?? $this->recompute($seller);
    }

    /**
     * The sellers a moderator should look at, worst first.
     *
     * Three independent reasons to appear here, because each catches a shop
     * the others miss.
     *
     * A low average rating, over enough reviews to mean anything. This is the
     * one that has to be stated separately rather than left to the score: a
     * shop with five one-star reviews, no disputes and a clean fulfilment
     * record still scores respectably on three of the four components, and
     * "nobody has fallen out with them yet" is not a reason to leave five
     * unhappy buyers unread.
     *
     * A dispute rate above the platform threshold — the shop with a handful
     * of glowing reviews whose orders have started going wrong.
     *
     * And the composite score itself, in Watch or Critical, which catches the
     * slow decline where no single component is alarming.
     *
     * @return Collection<int, SellerTrustScore>
     */
    public function reviewList(): Collection
    {
        $disputeThreshold = (float) settings('risk.dispute_rate_threshold_percent', 2);
        $ratingThreshold = (float) settings('ratings.review_list.min_average', 3);
        $minimumRatings = (int) settings('ratings.review_list.min_ratings', 3);

        return SellerTrustScore::query()
            ->with('seller')
            ->where(function (Builder $query) use ($disputeThreshold, $ratingThreshold, $minimumRatings): void {
                $query->whereIn('trust_band', [TrustBand::Watch->value, TrustBand::Critical->value])
                    ->orWhere('dispute_rate_percent', '>', $disputeThreshold)
                    ->orWhere(function (Builder $poorlyRated) use ($ratingThreshold, $minimumRatings): void {
                        $poorlyRated->where('ratings_count', '>=', $minimumRatings)
                            ->where('average_stars', '<', $ratingThreshold);
                    });
            })
            ->orderBy('trust_score')
            ->get();
    }

    /**
     * The band, with one override.
     *
     * A shop averaging one star, with no disputes and every order completed,
     * scores in the sixties: three quarters of the weight is about things
     * that have gone right. That number is honest, but the LABEL on it is
     * not — "Fair" beside five one-star reviews reads as an instruction to
     * move on, and the recommended actions that come with it say exactly
     * that.
     *
     * So a poor average caps the band at Watch however well the rest scores.
     * A shop can be doing everything else correctly and still be one its
     * buyers are unhappy with, and that is precisely the case a moderator is
     * meant to look at.
     */
    private function band(float $score, RatingAggregate $aggregate): TrustBand
    {
        $band = TrustBand::forScore($score);

        if (! $this->isPoorlyRated($aggregate) || $band->needsReview()) {
            return $band;
        }

        return TrustBand::Watch;
    }

    /**
     * Whether enough buyers have rated this shop badly for it to mean
     * something. One unhappy buyer out of two is not a pattern.
     */
    private function isPoorlyRated(RatingAggregate $aggregate): bool
    {
        $minimumRatings = (int) settings('ratings.review_list.min_ratings', 3);
        $threshold = (float) settings('ratings.review_list.min_average', 3);

        return $aggregate->count >= $minimumRatings
            && $aggregate->average !== null
            && $aggregate->average < $threshold;
    }

    /**
     * The weighted score, out of whatever the weights add up to (100 by
     * default).
     */
    private function score(RatingAggregate $aggregate, float $disputeRate, Seller $seller, int $completed): float
    {
        $weights = $this->weights();

        $score = $weights['rating'] * $this->ratingFactor($aggregate)
            + $weights['volume'] * $this->volumeFactor($aggregate->count)
            + $weights['low_disputes'] * $this->disputeFactor($disputeRate)
            + $weights['fulfilment'] * $this->fulfilmentFactor($seller, $completed);

        return round($score, 2);
    }

    /**
     * An average pulled towards the prior by how few people are behind it.
     */
    private function ratingFactor(RatingAggregate $aggregate): float
    {
        $count = max(0, $aggregate->count);
        $average = $aggregate->average ?? self::PRIOR_RATING;

        $adjusted = (self::PRIOR_RATING * self::PRIOR_WEIGHT + $average * $count)
            / (self::PRIOR_WEIGHT + $count);

        return $this->clamp($adjusted / self::RATING_SCALE);
    }

    /**
     * Log-scaled, so a shop's first handful of reviews are worth the most.
     */
    private function volumeFactor(int $count): float
    {
        if ($count <= 0) {
            return 0.0;
        }

        return $this->clamp(log(1 + $count) / log(1 + self::REVIEW_SATURATION));
    }

    /**
     * Full marks at no disputes, nothing at twice the platform threshold.
     */
    private function disputeFactor(float $ratePercent): float
    {
        $threshold = max(0.01, (float) settings('risk.dispute_rate_threshold_percent', 2));

        return $this->clamp(1 - ($ratePercent / ($threshold * 2)));
    }

    /**
     * The share of a seller's paid orders that actually completed.
     *
     * A seller who takes money and then cancels is not caught by the dispute
     * rate — a buyer who gets an automatic refund rarely bothers to dispute —
     * so this is the component that notices.
     */
    private function fulfilmentFactor(Seller $seller, int $completed): float
    {
        $paid = Order::query()
            ->where('seller_id', $seller->getKey())
            ->whereNotNull('paid_at')
            ->count();

        if ($paid === 0) {
            /* Nothing to judge. Neutral rather than zero, for the same
             * reason an unrated seller is not ranked last. */
            return 0.5;
        }

        return $this->clamp($completed / $paid);
    }

    private function completedOrderCount(Seller $seller): int
    {
        return Order::query()
            ->where('seller_id', $seller->getKey())
            ->where('status', OrderStatus::Completed)
            ->count();
    }

    private function trailingDays(): int
    {
        return (int) settings('risk.reserve_trailing_days', 30);
    }

    /**
     * The three figures Search reads off a seller. Anything else moving is
     * not worth a re-index.
     *
     * @return array<int, float|int|null>
     */
    private function rankingComponents(?SellerTrustScore $score): array
    {
        if ($score === null) {
            return [null, -1, -1.0];
        }

        return [
            $score->average_stars === null ? null : round($score->average_stars, 2),
            $score->ratings_count,
            round($score->dispute_rate_percent, 2),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function weights(): array
    {
        $weights = [];

        foreach (self::WEIGHTS as $key => $default) {
            $weights[$key] = (float) settings('ratings.trust.weight.'.$key, $default);
        }

        return $weights;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
