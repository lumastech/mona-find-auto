<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Models;

use App\Models\User;
use App\Modules\Privacy\Database\Factories\ConsentRecordFactory;
use App\Modules\Privacy\Enums\ConsentType;
use App\Support\Database\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One grant or withdrawal of one consent, by one person, at one moment.
 *
 * Append-only. The current position is not a column anywhere — it is the
 * newest row for a (user, type) pair, which is what `latestFor()` reads.
 *
 * @property int $id
 * @property int $user_id
 * @property ConsentType $type
 * @property bool $granted
 * @property int|null $document_version
 * @property string|null $document_slug
 * @property string $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 * @property-read User $user
 */
class ConsentRecord extends Model
{
    /** @use HasFactory<ConsentRecordFactory> */
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'granted' => 'boolean',
            'document_version' => 'integer',
            'recorded_at' => 'datetime',
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
     * The row that decides where a person currently stands on one consent.
     *
     * Ordered by `recorded_at` and then by id, because a grant and a
     * withdrawal recorded in the same second are ordered by nothing else and
     * getting that backwards would report the opposite of the truth.
     *
     * @param  Builder<ConsentRecord>  $query
     * @return Builder<ConsentRecord>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('recorded_at')->orderByDesc('id');
    }
}
