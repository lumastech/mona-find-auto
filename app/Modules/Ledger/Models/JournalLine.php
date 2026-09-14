<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Support\PostingGuard;
use App\Support\Database\AppendOnly;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One side of one movement: an account, a direction, a positive amount.
 *
 * Every balance the platform reports is a sum over these rows, which is why
 * they are append-only and why nothing but LedgerService may write one. The
 * materialised `ledger_balances` table is a convenience on top; this is the
 * record.
 *
 * Like JournalEntry, deliberately without a factory — see that class.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $ledger_account_id
 * @property EntryDirection $direction
 * @property Money $amount_ngwee
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $memo
 * @property Carbon $posted_at
 * @property Carbon|null $created_at
 * @property-read JournalEntry $entry
 * @property-read LedgerAccount $account
 * @property-read Model|null $subject
 */
class JournalLine extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => EntryDirection::class,
            'amount_ngwee' => MoneyCast::class,
            'posted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static fn (self $line) => PostingGuard::assertOpen($line));
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * The order whose escrow this is, or the seller whose payable it is.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isDebit(): bool
    {
        return $this->direction === EntryDirection::Debit;
    }

    /**
     * The amount signed against the account's normal balance: positive when
     * this line increased the account, negative when it reduced it.
     */
    public function signedAmount(): Money
    {
        $normal = $this->account->code->normalBalance();

        return $this->direction === $normal
            ? $this->amount_ngwee
            : $this->amount_ngwee->negated();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForAccount(Builder $query, LedgerAccountCode $code): void
    {
        $query->whereHas('account', fn (Builder $account) => $account->where('code', $code->value));
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForSubject(Builder $query, ?Model $subject): void
    {
        if ($subject === null) {
            $query->whereNull('subject_type');

            return;
        }

        $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }
}
