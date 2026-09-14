<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Database\Factories;

use App\Modules\Ratings\Enums\TrustBand;
use App\Modules\Ratings\Models\SellerTrustScore;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerTrustScore>
 */
class SellerTrustScoreFactory extends Factory
{
    protected $model = SellerTrustScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $average = $this->faker->randomFloat(2, 3.5, 5);
        $count = $this->faker->numberBetween(1, 80);
        $score = round($average / 5 * 100, 2);

        return [
            'seller_id' => Seller::factory(),
            'ratings_count' => $count,
            'average_stars' => $average,
            'star_breakdown' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => $count],
            'dispute_rate_percent' => 0,
            'completed_orders' => $count,
            'trust_score' => $score,
            'trust_band' => TrustBand::forScore($score),
            'computed_at' => now(),
        ];
    }

    /**
     * A shop a moderator should be looking at.
     */
    public function needingReview(): static
    {
        return $this->state([
            'ratings_count' => 12,
            'average_stars' => 2.1,
            'star_breakdown' => [1 => 6, 2 => 3, 3 => 2, 4 => 1, 5 => 0],
            'dispute_rate_percent' => 8.5,
            'trust_score' => 31.4,
            'trust_band' => TrustBand::Critical,
        ]);
    }
}
