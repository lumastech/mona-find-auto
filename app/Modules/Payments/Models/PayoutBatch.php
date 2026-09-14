<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Payments\Database\Factories\PayoutBatchFactory;
use App\Modules\Payments\Enums\PayoutBatchStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A run of seller payouts under dual control.
 *
 * @property int $id
 * @property string $reference
 * @property PayoutBatchStatus $status
 * @property int $line_count
 * @property Money $total_ngwee
 * @property Money $paid_ngwee
 * @property Money $failed_ngwee
 * @property int|null $prepared_by
 * @property int|null $approved_by
 * @property string|null $approval_note
 * @property string|null $cancellation_reason
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $approved_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property-read Collection<int, PayoutLine> $lines
 * @property-read User|null $preparedBy
 * @property-read User|null $approvedBy
 */
class PayoutBatch extends Model
{
    /** @use HasFactory<PayoutBatchFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutBatchStatus::class,
            'line_count' => 'integer',
            'total_ngwee' => MoneyCast::class,
            'paid_ngwee' => MoneyCast::class,
            'failed_ngwee' => MoneyCast::class,
            'scheduled_for' => 'datetime',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PayoutLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayoutLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * Whether $user may approve this batch.
     *
     * Dual control lives here as well as in the service so the UI can hide a
     * button the server would refuse anyway. The rule is deliberately blunt:
     * whoever built it cannot release it, no matter what roles they hold.
     */
    public function isApprovableBy(User $user): bool
    {
        return $this->status->isApprovable()
            && $this->prepared_by !== $user->getKey();
    }

    /**
     * Lines a human still has to deal with after the run.
     *
     * @return Collection<int, PayoutLine>
     */
    public function linesNeedingAttention(): Collection
    {
        return $this->lines->filter(static fn (PayoutLine $line): bool => $line->status->needsAttention())->values();
    }

    /**
     * @param  Builder<PayoutBatch>  $query
     * @return Builder<PayoutBatch>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PayoutBatchStatus::Draft->value,
            PayoutBatchStatus::AwaitingApproval->value,
            PayoutBatchStatus::Approved->value,
            PayoutBatchStatus::Processing->value,
        ]);
    }
}
