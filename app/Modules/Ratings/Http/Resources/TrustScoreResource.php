<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Resources;

use App\Modules\Ratings\Models\SellerTrustScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seller's trust row, for the moderator's review list.
 *
 * The recommended actions travel with the row rather than being worked out in
 * the template, so that the console and any future report agree about what
 * the platform expects to be done about a shop in this state.
 *
 * @mixin SellerTrustScore
 */
class TrustScoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SellerTrustScore $score */
        $score = $this->resource;
        $aggregate = $score->aggregate();

        return [
            'seller' => [
                'id' => $score->seller_id,
                'name' => $score->seller->business_name,
                'slug' => $score->seller->slug,
                'verification_status' => $score->seller->verification_status->value,
                'payment_mode' => $score->seller->payment_mode->value,
            ],
            'ratings_count' => $score->ratings_count,
            'average_stars' => $score->average_stars,
            'breakdown' => $aggregate->toArray(),
            'dispute_rate_percent' => $score->dispute_rate_percent,
            'completed_orders' => $score->completed_orders,
            'trust_score' => $score->trust_score,
            'trust_band' => $score->trust_band->value,
            'trust_band_label' => $score->trust_band->label(),
            'trust_band_variant' => $score->trust_band->badgeVariant(),
            'recommended_actions' => $score->trust_band->recommendedActions(),
            'computed_at' => $score->computed_at?->toIso8601String(),
        ];
    }
}
