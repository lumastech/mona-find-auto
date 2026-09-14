<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Models;

use App\Models\User;
use App\Modules\Ratings\Database\Factories\RatingReportFactory;
use App\Modules\Ratings\Enums\ReportReason;
use App\Modules\Ratings\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An objection to a review.
 *
 * A report is evidence, not a verdict: upholding one hides the review and
 * dismissing one leaves it where it is, and either way a person decides. The
 * only thing a report does on its own is take an abusive review or one
 * carrying somebody's personal details off the page while it waits — see
 * ReportReason::hidesImmediately().
 *
 * @property int $id
 * @property int $rating_id
 * @property int $reported_by
 * @property ReportReason $reason
 * @property string|null $details
 * @property ReportStatus $status
 * @property string|null $resolution_note
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Rating $rating
 * @property-read User $reporter
 * @property-read User|null $reviewer
 */
class RatingReport extends Model
{
    /** @use HasFactory<RatingReportFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rating, $this>
     */
    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', ReportStatus::openValues());
    }
}
