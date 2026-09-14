<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Models;

use App\Models\User;
use App\Modules\Privacy\Database\Factories\ErasureRequestFactory;
use App\Modules\Privacy\Enums\ErasureStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to erase an account, and the record of what was erased.
 *
 * Not append-only: the row moves through statuses, gains a report, and can be
 * cancelled. What must not change is the fact that it existed, which is what
 * the audit trail carries.
 *
 * @property int $id
 * @property int $user_id
 * @property ErasureStatus $status
 * @property string|null $reason
 * @property Carbon $requested_at
 * @property Carbon $erase_after
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $blocked_reason
 * @property int|null $blocked_by
 * @property array<string, array<string, int>>|null $report
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class ErasureRequest extends Model
{
    /** @use HasFactory<ErasureRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ErasureStatus::class,
            'report' => 'array',
            'requested_at' => 'datetime',
            'erase_after' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /**
     * Requests that are still going to happen.
     *
     * @param  Builder<ErasureRequest>  $query
     * @return Builder<ErasureRequest>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [ErasureStatus::Pending, ErasureStatus::Blocked]);
    }

    /**
     * Requests the scheduled sweep should act on: past the grace period and
     * not held by staff.
     *
     * @param  Builder<ErasureRequest>  $query
     * @return Builder<ErasureRequest>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', ErasureStatus::Pending)
            ->where('erase_after', '<=', now());
    }

    /**
     * How many tables gave something up, flattened out of the report.
     */
    public function recordsErased(): int
    {
        $total = 0;

        foreach ($this->report ?? [] as $tables) {
            $total += array_sum($tables);
        }

        return $total;
    }
}
