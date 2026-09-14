<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Models;

use App\Modules\Ratings\Database\Factories\SellerTrustScoreFactory;
use App\Modules\Ratings\Enums\TrustBand;
use App\Modules\Ratings\Support\RatingAggregate;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A seller's reputation as one row.
 *
 * Maintained by TrustScoreService and rebuilt nightly. Everything that reads a
 * seller's rating at scale — the search index, the admin review list, the
 * seller's own dashboard — reads it here rather than aggregating the ratings
 * table again.
 *
 * @property int $id
 * @property int $seller_id
 * @property int $ratings_count
 * @property float|null $average_stars
 * @property array<int, int>|null $star_breakdown
 * @property float $dispute_rate_percent
 * @property int $completed_orders
 * @property float $trust_score
 * @property TrustBand $trust_band
 * @property Carbon|null $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Seller $seller
 */
class SellerTrustScore extends Model
{
    /** @use HasFactory<SellerTrustScoreFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ratings_count' => 'integer',
            'average_stars' => 'float',
            'star_breakdown' => 'array',
            'dispute_rate_percent' => 'float',
            'completed_orders' => 'integer',
            'trust_score' => 'float',
            'trust_band' => TrustBand::class,
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * The star breakdown as the storefront's bars want it.
     */
    public function aggregate(): RatingAggregate
    {
        return RatingAggregate::fromCounts($this->star_breakdown ?? []);
    }

    /**
     * Sellers a moderator should look at, worst first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNeedingReview(Builder $query): void
    {
        $query->whereIn('trust_band', [TrustBand::Watch->value, TrustBand::Critical->value])
            ->orderBy('trust_score');
    }
}
