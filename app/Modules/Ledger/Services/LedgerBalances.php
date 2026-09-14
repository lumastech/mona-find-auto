<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Models\LedgerBalance;
use App\Modules\Ledger\Support\PostingLine;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every balance the platform reports, read two ways.
 *
 * `recompute()` sums the journal lines and is the truth. The cached
 * `ledger_balances` rows are what the console and the seller portal actually
 * read, because summing every line a seller has ever had gets slower every
 * day the platform trades.
 *
 * Keeping both and being able to compare them is the whole design. A
 * materialised balance nobody can check against the lines is a number nobody
 * should trust; the consistency test posts a thousand random entries and
 * expects the two to agree to the ngwee, and `discrepancies()` is the same
 * check available to a reconciliation job.
 *
 * Two rows are maintained per line: the subject's (this order's escrow) and
 * the account-wide one. Deriving the account total by summing subject rows
 * would work right up until the first platform-wide account, which has no
 * subject rows at all.
 */
class LedgerBalances
{
    /**
     * Fold a line into the cached balances.
     *
     * Called by LedgerService inside the posting transaction, so it either
     * happens with the line or not at all. The writes are SQL-level
     * increments rather than read-modify-write in PHP: two workers posting
     * against the same seller at the same instant must not read the same
     * starting figure and both write their own total over it.
     */
    public function apply(PostingLine $line, LedgerAccount $account, JournalLine $row): void
    {
        $signed = $line->signedAmount();

        /* The account-wide row, and — when the line names one — the subject's. */
        $this->increment($account, null, null, $line->direction, $line->amount, $signed, $row);

        if ($line->subjectType !== null && $line->subjectId !== null) {
            $this->increment($account, $line->subjectType, $line->subjectId, $line->direction, $line->amount, $signed, $row);
        }
    }

    private function increment(
        LedgerAccount $account,
        ?string $subjectType,
        ?int $subjectId,
        EntryDirection $direction,
        Money $amount,
        Money $signed,
        JournalLine $row,
    ): void {
        $key = LedgerBalance::keyFor($subjectType, $subjectId);

        /*
         * firstOrCreate rather than an upsert of the totals: the row has to
         * exist before it can be incremented, and creating it with zeroes
         * means the increment below is the only thing that ever touches the
         * figures — one code path whether the row is new or not.
         */
        $balance = LedgerBalance::query()->firstOrCreate(
            ['ledger_account_id' => $account->getKey(), 'subject_key' => $key],
            [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'debit_ngwee' => 0,
                'credit_ngwee' => 0,
                'balance_ngwee' => 0,
                'line_count' => 0,
            ],
        );

        $column = $direction === EntryDirection::Debit ? 'debit_ngwee' : 'credit_ngwee';

        /*
         * incrementEach() emits `SET col = col + n` in one statement, so two
         * workers posting against the same seller at the same instant queue
         * in the database rather than both reading the same starting figure
         * and writing their own total over it.
         */
        LedgerBalance::query()->whereKey($balance->getKey())->incrementEach(
            [
                $column => $amount->ngwee,
                'balance_ngwee' => $signed->ngwee,
                'line_count' => 1,
            ],
            [
                'last_journal_line_id' => $row->getKey(),
                'last_posted_at' => $row->posted_at,
                'updated_at' => now(),
            ],
        );
    }

    /**
     * The cached balance of an account, across every subject.
     */
    public function forAccount(LedgerAccountCode $code): Money
    {
        return $this->cached($code, null, null);
    }

    /**
     * The cached balance of an account for one subject.
     */
    public function forSubject(LedgerAccountCode $code, Model $subject): Money
    {
        return $this->cached($code, $subject->getMorphClass(), (int) $subject->getKey());
    }

    private function cached(LedgerAccountCode $code, ?string $subjectType, ?int $subjectId): Money
    {
        $balance = LedgerBalance::query()
            ->where('ledger_account_id', LedgerAccount::for($code)->getKey())
            ->where('subject_key', LedgerBalance::keyFor($subjectType, $subjectId))
            ->first();

        return $balance->balance_ngwee ?? Money::zero();
    }

    /**
     * What is still held against one order.
     *
     * Zero once the order has completed or been refunded; anything else means
     * the order is still in flight. This is the figure a release or a refund
     * is checked against, and it is read from the cache — `recompute()` is
     * there for when it has to be certain.
     */
    public function escrowHeldFor(Order $order): Money
    {
        return $this->forSubject(LedgerAccountCode::EscrowHeld, $order);
    }

    /**
     * What is owed to a seller and not yet paid out.
     *
     * Goes negative after a clawback on a direct-settlement seller: they have
     * had money the platform has since given back to a buyer, and the debt
     * nets off against their next sales.
     */
    public function sellerPayable(Seller $seller): Money
    {
        return $this->forSubject(LedgerAccountCode::SellerPayable, $seller);
    }

    /**
     * The rolling reserve currently withheld from a seller.
     */
    public function sellerReserve(Seller $seller): Money
    {
        return $this->forSubject(LedgerAccountCode::SellerReserve, $seller);
    }

    /**
     * The same figure, summed from the lines themselves.
     *
     * Slower and always right. Anything that has to be certain — a
     * reconciliation run, a payout about to leave the platform — reads this.
     */
    public function recompute(LedgerAccountCode $code, ?Model $subject = null): Money
    {
        $query = JournalLine::query()
            ->where('ledger_account_id', LedgerAccount::for($code)->getKey());

        if ($subject !== null) {
            $query->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey());
        }

        $normal = $code->normalBalance();

        $debits = (int) (clone $query)->where('direction', EntryDirection::Debit->value)->sum('amount_ngwee');
        $credits = (int) (clone $query)->where('direction', EntryDirection::Credit->value)->sum('amount_ngwee');

        return Money::ofNgwee(
            $normal === EntryDirection::Debit ? $debits - $credits : $credits - $debits,
        );
    }

    /**
     * Cached rows whose figure disagrees with the lines beneath it.
     *
     * Should always be empty. A non-empty result means something wrote a
     * journal line outside LedgerService — which the posting guard makes very
     * hard, but "hard" and "impossible" are worth a nightly check apart.
     *
     * @return array<int, array{account: string, subject_key: string, cached_ngwee: int, actual_ngwee: int}>
     */
    public function discrepancies(): array
    {
        return LedgerBalance::query()
            ->with('account')
            ->get()
            ->map(function (LedgerBalance $balance): ?array {
                $code = $balance->account->code;

                $actual = $balance->isAccountWide()
                    ? $this->recomputeByKey($code, null, null)
                    : $this->recomputeByKey($code, $balance->subject_type, $balance->subject_id);

                if ($actual->equals($balance->balance_ngwee)) {
                    return null;
                }

                return [
                    'account' => $code->value,
                    'subject_key' => $balance->subject_key,
                    'cached_ngwee' => $balance->balance_ngwee->ngwee,
                    'actual_ngwee' => $actual->ngwee,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Rebuild the cache from the lines.
     *
     * The repair for a discrepancy, and the migration path for a balance
     * dimension added after the fact. Deliberately not scheduled: a cache
     * that silently rebuilds itself hides the fact that it was wrong.
     */
    public function rebuild(): int
    {
        return DB::transaction(function (): int {
            LedgerBalance::query()->delete();

            /* The chart is ten rows; resolving it once beats a lookup per group. */
            $codes = LedgerAccount::query()->pluck('code', 'id');

            /*
             * Straight through the query builder rather than the model: these
             * are aggregate rows, not journal lines, and hydrating them as
             * JournalLine would cast columns that are sums and leave the
             * append-only guard wondering what it is looking at.
             */
            $rows = DB::table('journal_lines')
                ->selectRaw('ledger_account_id, subject_type, subject_id, direction, SUM(amount_ngwee) as total, COUNT(*) as line_count, MAX(id) as last_id, MAX(posted_at) as last_posted_at')
                ->groupBy('ledger_account_id', 'subject_type', 'subject_id', 'direction')
                ->get();

            /** @var array<string, array<string, mixed>> $balances */
            $balances = [];

            foreach ($rows as $row) {
                $accountId = (int) $row->ledger_account_id;
                $normal = $codes->get($accountId)->normalBalance();
                $direction = EntryDirection::from((string) $row->direction);
                $total = (int) $row->total;

                foreach ($this->targetsFor($accountId, $row->subject_type, $row->subject_id) as $target) {
                    [$key, $subjectType, $subjectId] = $target;

                    $balances[$key] ??= [
                        'ledger_account_id' => $accountId,
                        'subject_type' => $subjectType,
                        'subject_id' => $subjectId,
                        'subject_key' => LedgerBalance::keyFor($subjectType, $subjectId),
                        'debit_ngwee' => 0,
                        'credit_ngwee' => 0,
                        'balance_ngwee' => 0,
                        'line_count' => 0,
                        'last_journal_line_id' => null,
                        'last_posted_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $balances[$key][$direction === EntryDirection::Debit ? 'debit_ngwee' : 'credit_ngwee'] += $total;
                    $balances[$key]['balance_ngwee'] += $direction === $normal ? $total : -$total;
                    $balances[$key]['line_count'] += (int) $row->line_count;
                    $balances[$key]['last_journal_line_id'] = max(
                        (int) $balances[$key]['last_journal_line_id'],
                        (int) $row->last_id,
                    );
                    $balances[$key]['last_posted_at'] = max(
                        (string) $balances[$key]['last_posted_at'],
                        (string) $row->last_posted_at,
                    );
                }
            }

            foreach (array_chunk(array_values($balances), 200) as $chunk) {
                LedgerBalance::query()->insert($chunk);
            }

            return count($balances);
        });
    }

    /**
     * The cache rows one aggregated group of lines contributes to: always the
     * account-wide row, plus the subject's when there is one.
     *
     * @return array<int, array{0: string, 1: string|null, 2: int|null}>
     */
    private function targetsFor(int $accountId, mixed $subjectType, mixed $subjectId): array
    {
        $targets = [[$accountId.'|platform', null, null]];

        if ($subjectType !== null && $subjectId !== null) {
            $targets[] = [$accountId.'|'.$subjectType.':'.$subjectId, (string) $subjectType, (int) $subjectId];
        }

        return $targets;
    }

    private function recomputeByKey(LedgerAccountCode $code, ?string $subjectType, ?int $subjectId): Money
    {
        $query = JournalLine::query()
            ->where('ledger_account_id', LedgerAccount::for($code)->getKey());

        if ($subjectType !== null && $subjectId !== null) {
            $query->where('subject_type', $subjectType)->where('subject_id', $subjectId);
        }

        $debits = (int) (clone $query)->where('direction', EntryDirection::Debit->value)->sum('amount_ngwee');
        $credits = (int) (clone $query)->where('direction', EntryDirection::Credit->value)->sum('amount_ngwee');

        return Money::ofNgwee(
            $code->normalBalance() === EntryDirection::Debit ? $debits - $credits : $credits - $debits,
        );
    }
}
