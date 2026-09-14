<?php

declare(strict_types=1);

namespace App\Modules\Search\Models;

use App\Models\User;
use App\Modules\Search\Database\Factories\SearchQueryFactory;
use App\Modules\Search\Enums\MatchTier;
use App\Modules\Search\Enums\SearchSort;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One search somebody ran.
 *
 * @property int $id
 * @property string|null $term
 * @property array<string, mixed>|null $filters
 * @property SearchSort $sort
 * @property MatchTier|null $tier
 * @property int $result_count
 * @property bool $zero_results
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class SearchQuery extends Model
{
    /** @use HasFactory<SearchQueryFactory> */
    use HasFactory;

    /** A log row is written once and never touched again. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'sort' => SearchSort::class,
            'tier' => MatchTier::class,
            'result_count' => 'integer',
            'zero_results' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeZeroResult(Builder $query): void
    {
        $query->where('zero_results', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSince(Builder $query, DateTimeInterface $from): void
    {
        $query->where('created_at', '>=', $from);
    }
}
