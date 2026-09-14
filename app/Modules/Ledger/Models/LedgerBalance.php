<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Services\LedgerBalances;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A running total, kept in step by the poster.
 *
 * Never a source of truth. Everything here can be recovered by summing
 * `journal_lines`, and LedgerBalances::recompute() does exactly that — which
 * is the only reason a cached balance is safe to show anyone.
 *
 * `balance_ngwee` is signed against the account's normal balance, so it reads
 * the way the account is spoken about: a positive escrow balance is money
 * held, and a negative seller payable is a seller who owes the platform after
 * a clawback.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $subject_key
 * @property Money $debit_ngwee
 * @property Money $credit_ngwee
 * @property Money $balance_ngwee
 * @property int $line_count
 * @property int|null $last_journal_line_id
 * @property Carbon|null $last_posted_at
 * @property-read LedgerAccount $account
 * @property-read Model|null $subject
 *
 * @see LedgerBalances
 */
class LedgerBalance extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_ngwee' => MoneyCast::class,
            'credit_ngwee' => MoneyCast::class,
            'balance_ngwee' => MoneyCast::class,
            'line_count' => 'integer',
            'last_posted_at' => 'datetime',
        ];
    }

    /**
     * The key the unique index runs on.
     *
     * A normalised string rather than the nullable morph pair, because MySQL
     * treats every NULL as distinct and would accept any number of
     * account-wide rows for the same account.
     */
    public static function keyFor(?string $subjectType, ?int $subjectId): string
    {
        return $subjectType === null || $subjectId === null
            ? 'platform'
            : $subjectType.':'.$subjectId;
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this row is the account-wide total rather than one subject's.
     */
    public function isAccountWide(): bool
    {
        return $this->subject_key === 'platform';
    }
}
