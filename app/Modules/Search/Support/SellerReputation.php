<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Search\Enums\DisputeBand;

/**
 * What buyers have made of a seller: their rating, how many of them said so,
 * and how often their orders went wrong.
 *
 * A value object rather than three loose arguments, because the three are
 * always needed together and a bare (float, int, float) signature is exactly
 * the kind of thing that gets called with the arguments swapped.
 */
final readonly class SellerReputation
{
    /**
     * @param  float|null  $ratingAverage  Out of 5, or null when nobody has rated the seller.
     * @param  int  $reviewCount  Completed ratings behind that average.
     * @param  float  $disputeRatePercent  Disputed share of completed orders.
     */
    public function __construct(
        public ?float $ratingAverage = null,
        public int $reviewCount = 0,
        public float $disputeRatePercent = 0.0,
    ) {}

    /**
     * A seller nobody has rated and nobody has fallen out with.
     */
    public static function unrated(): self
    {
        return new self;
    }

    public function band(float $thresholdPercent): DisputeBand
    {
        return DisputeBand::forRate($this->disputeRatePercent, $thresholdPercent);
    }
}
