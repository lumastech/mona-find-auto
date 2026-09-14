<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Database\Factories\LedgerAdjustmentFactory;
use App\Modules\Ledger\Enums\AdjustmentStatus;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Services\LedgerAdjustmentService;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A correction one member of staff wrote and another has to approve.
 *
 * The only place on the platform where a person moves money because they say
 * so, which is exactly why it takes two of them. Finance drafts; an
 * administrator approves; the approval is what posts. Until then the draft
 * lines are JSON on this row and nothing has reached the ledger — a journal
 * line that exists but has moved no money would be the one ambiguity the
 * ledger is built to avoid.
 *
 * This row is not append-only, because it carries a decision that has not
 * been taken yet. What it produces on approval is.
 *
 * @property int $id
 * @property AdjustmentStatus $status
 * @property string $description
 * @property string $reason
 * @property array<int, array<string, mixed>> $lines
 * @property Money $total_ngwee
 * @property int $created_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property int|null $journal_entry_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read User|null $decider
 * @property-read JournalEntry|null $entry
 *
 * @see LedgerAdjustmentService
 */
class LedgerAdjustment extends Model
{
    /** @use HasFactory<LedgerAdjustmentFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AdjustmentStatus::class,
            'lines' => 'array',
            'total_ngwee' => MoneyCast::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function isPending(): bool
    {
        return $this->status === AdjustmentStatus::Pending;
    }

    /**
     * Whether this person may decide this adjustment.
     *
     * The dual-control rule in one line: not the author, and not already
     * decided. The role check is the policy's business.
     */
    public function isDecidableBy(User $user): bool
    {
        return $this->isPending() && $this->created_by !== $user->getKey();
    }

    /**
     * The draft lines, in a shape the console can render without knowing the
     * ledger's internals.
     *
     * @return array<int, array{account: string, account_label: string, direction: string, amount_ngwee: int, subject_type: string|null, subject_id: int|null, memo: string|null}>
     */
    public function readableLines(): array
    {
        return array_map(static function (array $line): array {
            $code = LedgerAccountCode::from((string) $line['account']);

            return [
                'account' => $code->value,
                'account_label' => $code->label(),
                'direction' => EntryDirection::from((string) $line['direction'])->value,
                'amount_ngwee' => (int) $line['amount_ngwee'],
                'subject_type' => $line['subject_type'] ?? null,
                'subject_id' => isset($line['subject_id']) ? (int) $line['subject_id'] : null,
                'memo' => $line['memo'] ?? null,
            ];
        }, $this->lines);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', AdjustmentStatus::Pending);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithStatus(Builder $query, ?AdjustmentStatus $status): void
    {
        if ($status !== null) {
            $query->where('status', $status);
        }
    }
}
