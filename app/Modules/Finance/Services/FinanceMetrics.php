<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\MetricDimension;
use App\Modules\Finance\Enums\MetricGranularity;
use App\Modules\Finance\Support\FinancePositions;
use App\Modules\Finance\Support\FinanceTotals;
use App\Modules\Finance\Support\MetricWindow;
use App\Modules\Finance\Support\MovementRow;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every figure on the finance dashboard, read from the ledger and nowhere else.
 *
 * ## The rule this class exists to enforce
 *
 * Not one number here is computed from an order, a payment row or a payout.
 * They are all sums of `journal_lines`. That is a deliberate constraint and it
 * costs something — attributing a commission to a province takes a join this
 * module would not otherwise need — so it is worth saying why.
 *
 * The ledger is the only place on the platform where money is guaranteed to
 * balance. An order's `total_ngwee` is what the buyer was asked for; a payment
 * row is what the gateway said; a payout line is what was attempted. Any of
 * the three can disagree with what actually moved, and when they do the
 * dashboard has to side with the books. A GMV figure summed from orders would
 * count an order whose payment silently failed, and a number nobody can
 * reconcile is a number everybody stops trusting.
 *
 * It also makes the dashboard checkable, which is what the acceptance test
 * does: every headline figure has a one-line ledger query behind it that a
 * finance officer can run by hand and compare.
 *
 * ## Flows and positions are read differently
 *
 * A flow (GMV, commission, refunds) is bounded by the window. A position
 * (escrow held, payables outstanding) is cumulative to the end of it —
 * "escrow held in September" is not a question; "escrow held at the end of
 * September" is. Two queries, deliberately.
 *
 * ## Dimensions hang off the order
 *
 * Revenue accounts are platform-wide and carry no subject, so the only thing
 * on a revenue entry that knows which province earned it is
 * `journal_entries.reference`, which points at the Order. Seller type and
 * province group exactly — one order, one seller, one province. Category does
 * not, and byCategory() apportions rather than pretending otherwise.
 */
class FinanceMetrics
{
    /** Recipes on which a buyer's money arrives. */
    private const PAYMENT_RECIPES = [
        PostingRecipe::EscrowPayment->value,
        PostingRecipe::DirectPayment->value,
    ];

    /** Recipes on which a buyer's money goes back. */
    private const REFUND_RECIPES = [
        PostingRecipe::EscrowRefund->value,
        PostingRecipe::DirectClawback->value,
        PostingRecipe::GoodwillRefund->value,
    ];

    /**
     * The headline figures for a window.
     */
    public function totals(MetricWindow $window): FinanceTotals
    {
        $fold = $this->fold($this->movementRows($window));

        return $this->totalsFrom($fold['matrix'], count($fold['orders']));
    }

    /**
     * The same figures for one seller — what a statement is built from.
     */
    public function totalsForSeller(MetricWindow $window, int $sellerId): FinanceTotals
    {
        $fold = $this->fold($this->movementRows(
            $window,
            scope: static fn (Builder $query) => $query->where('orders.seller_id', $sellerId),
        ));

        return $this->totalsFrom($fold['matrix'], count($fold['orders']));
    }

    /**
     * What was PAID OUT to a seller in the window.
     *
     * Read off the line's subject rather than off an order, because a payout
     * entry references the payout — not a trade — and there is no order to
     * join to. Payout entries are posted on CONFIRMATION, so this counts
     * money that actually left and never a transfer that was merely attempted.
     */
    public function sellerPayouts(MetricWindow $window, int $sellerId): Money
    {
        return $this->sellerSubjectMovement(
            $window,
            $sellerId,
            LedgerAccountCode::SellerPayable,
            EntryDirection::Debit,
            [PostingRecipe::Payout->value],
        );
    }

    /**
     * Reserve withheld from, and released back to, a seller in the window.
     *
     * @return array{withheld: Money, released: Money}
     */
    public function sellerReserveMovement(MetricWindow $window, int $sellerId): array
    {
        return [
            'withheld' => $this->sellerSubjectMovement(
                $window,
                $sellerId,
                LedgerAccountCode::SellerReserve,
                EntryDirection::Credit,
            ),
            'released' => $this->sellerSubjectMovement(
                $window,
                $sellerId,
                LedgerAccountCode::SellerReserve,
                EntryDirection::Debit,
            ),
        ];
    }

    /**
     * A seller's position on an account at the last instant of the window.
     *
     * Cumulative, and signed against the account's normal balance — so a
     * negative payable means the seller owes the platform after a clawback,
     * which is exactly what their statement needs to say.
     */
    public function sellerClosingBalance(MetricWindow $window, int $sellerId, LedgerAccountCode $code): Money
    {
        [, $to] = $window->bounds();

        $rows = $this->baseQuery()
            ->where('la.code', $code->value)
            ->where('jl.subject_type', Seller::class)
            ->where('jl.subject_id', $sellerId)
            ->where('jl.posted_at', '<=', $to)
            ->groupBy('jl.direction')
            ->select('jl.direction', DB::raw('SUM(jl.amount_ngwee) as total'))
            ->get();

        $debit = 0;
        $credit = 0;

        foreach ($rows as $row) {
            if ((string) $row->direction === EntryDirection::Debit->value) {
                $debit = (int) $row->total;
            } else {
                $credit = (int) $row->total;
            }
        }

        return Money::ofNgwee($code->normalBalance() === EntryDirection::Debit
            ? $debit - $credit
            : $credit - $debit);
    }

    /**
     * One side of one account, for one seller, within the window.
     *
     * @param  array<int, string>|null  $recipes
     */
    private function sellerSubjectMovement(
        MetricWindow $window,
        int $sellerId,
        LedgerAccountCode $code,
        EntryDirection $direction,
        ?array $recipes = null,
    ): Money {
        [$from, $to] = $window->bounds();

        $total = $this->baseQuery()
            ->where('la.code', $code->value)
            ->where('jl.direction', $direction->value)
            ->where('jl.subject_type', Seller::class)
            ->where('jl.subject_id', $sellerId)
            ->whereBetween('jl.posted_at', [$from, $to])
            ->when($recipes !== null, static fn (Builder $query) => $query->whereIn('je.recipe', $recipes))
            ->sum('jl.amount_ngwee');

        return Money::ofNgwee((int) $total);
    }

    /**
     * Where the money stands at the end of a window.
     *
     * Cumulative from the first entry ever posted, which is what makes these
     * comparable with `ledger_balances` — and comparable is the point: a
     * position on this screen that disagrees with the ledger browser is a bug
     * somebody needs to see.
     */
    public function positions(MetricWindow $window): FinancePositions
    {
        [, $to] = $window->bounds();

        $rows = $this->baseQuery()
            ->where('jl.posted_at', '<=', $to)
            ->groupBy('la.code', 'jl.direction')
            ->select('la.code', 'jl.direction', DB::raw('SUM(jl.amount_ngwee) as total'))
            ->get();

        $matrix = [];

        foreach ($rows as $row) {
            $matrix[(string) $row->code][(string) $row->direction] = (int) $row->total;
        }

        $balance = static function (LedgerAccountCode $code) use ($matrix): Money {
            $debit = (int) ($matrix[$code->value][EntryDirection::Debit->value] ?? 0);
            $credit = (int) ($matrix[$code->value][EntryDirection::Credit->value] ?? 0);

            /* Signed against the account's normal balance, exactly as ledger_balances stores it. */
            return Money::ofNgwee($code->normalBalance() === EntryDirection::Debit
                ? $debit - $credit
                : $credit - $debit);
        };

        return new FinancePositions(
            platformCash: $balance(LedgerAccountCode::PlatformCash),
            escrowHeld: $balance(LedgerAccountCode::EscrowHeld),
            payablesOutstanding: $balance(LedgerAccountCode::SellerPayable),
            reserveHeld: $balance(LedgerAccountCode::SellerReserve),
            vatOwed: $balance(LedgerAccountCode::VatOnCommissionPayable),
        );
    }

    /**
     * The window's figures cut into buckets.
     *
     * Empty buckets are filled in, so a quiet week shows as a zero rather than
     * as a gap the chart closes up and misrepresents as continuous trading.
     *
     * @return array<int, array<string, int|string>>
     */
    public function series(MetricWindow $window, MetricGranularity $granularity): array
    {
        $folded = [];

        foreach ($this->movementRows($window, withLocalDate: true) as $row) {
            if ($row->localDate === null) {
                continue;
            }

            $key = $granularity->bucket(CarbonImmutable::parse($row->localDate))->toDateString();
            $folded[$key] ??= ['matrix' => [], 'orders' => []];
            $this->foldRow($row, $folded[$key]);
        }

        $series = [];
        $cursor = $granularity->bucket($window->from);
        $end = $granularity->bucket($window->to);

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $bucket = $folded[$key] ?? ['matrix' => [], 'orders' => []];

            $series[] = [
                'bucket' => $key,
                'label' => $granularity->labelFor($cursor),
                ...$this->totalsFrom($bucket['matrix'], count($bucket['orders']))->toArray(),
            ];

            $cursor = $granularity->advance($cursor);
        }

        return $series;
    }

    /**
     * The window's figures split by seller type, province or category.
     *
     * @return array<int, array<string, int|string|bool>>
     */
    public function breakdown(MetricWindow $window, MetricDimension $dimension): array
    {
        return $dimension === MetricDimension::Category
            ? $this->byCategory($window)
            : $this->bySellerAttribute($window, $dimension);
    }

    /**
     * Seller type and province: an exact group-by.
     *
     * One order has one seller, who has one type and sits in one province, so
     * nothing is apportioned and the rows add back to the headline figures.
     *
     * @return array<int, array<string, int|string|bool>>
     */
    private function bySellerAttribute(MetricWindow $window, MetricDimension $dimension): array
    {
        /* A fixed choice of two column names, never anything a request supplied. */
        $column = $dimension === MetricDimension::Province ? 'provinces.name' : 'sellers.type';

        $rows = $this->movementRows(
            $window,
            scope: static fn (Builder $query) => $query
                ->leftJoin('sellers', 'sellers.id', '=', 'orders.seller_id')
                ->leftJoin('provinces', 'provinces.id', '=', 'sellers.province_id')
                ->addSelect($column.' as dimension_key')
                ->groupBy($column),
        );

        $groups = [];

        foreach ($rows as $row) {
            $key = $row->dimensionKey ?? '';
            $groups[$key] ??= ['matrix' => [], 'orders' => []];
            $this->foldRow($row, $groups[$key]);
        }

        $out = [];

        foreach ($groups as $key => $group) {
            $out[] = [
                'key' => $key === '' ? 'unknown' : $key,
                'label' => $this->labelFor($key, $dimension),
                'apportioned' => false,
                ...$this->totalsFrom($group['matrix'], count($group['orders']))->toArray(),
            ];
        }

        return $this->sortByGmv($out);
    }

    /**
     * Category: an apportionment, and labelled as one on the screen.
     *
     * An order for a gearbox and a set of plugs earned one commission, and
     * there is no honest way to say which of the two categories earned it. The
     * order's figures are therefore split across its categories in proportion
     * to what was bought, using Money::allocate() so the parts add back to the
     * whole — the rows still total the headline figure to the ngwee, which is
     * what makes this a breakdown rather than an estimate.
     *
     * Done per order in PHP rather than in SQL because allocate()'s
     * largest-remainder rule has no portable SQL equivalent, and a breakdown
     * whose rows do not sum to the total is worse than no breakdown at all.
     *
     * @return array<int, array<string, int|string|bool>>
     */
    private function byCategory(MetricWindow $window): array
    {
        $perOrder = $this->totalsPerOrder($window);

        if ($perOrder === []) {
            return [];
        }

        $weights = $this->categoryWeights(array_keys($perOrder));
        $totals = [];
        $labels = [];
        $orderCounts = [];

        foreach ($perOrder as $orderId => $orderTotals) {
            $lines = $weights[$orderId] ?? [];

            if ($lines === []) {
                continue;
            }

            $categoryIds = array_keys($lines);
            $ratios = array_map(static fn (array $line): int => $line['weight'], $lines);
            $denominator = array_sum($ratios);

            if ($denominator <= 0) {
                continue;
            }

            foreach ($categoryIds as $categoryId) {
                $labels[$categoryId] ??= $lines[$categoryId]['label'];
                $orderCounts[$categoryId] = ($orderCounts[$categoryId] ?? 0) + $orderTotals->orderCount;

                $share = $orderTotals->apportion($lines[$categoryId]['weight'], $denominator);

                $totals[$categoryId] = isset($totals[$categoryId])
                    ? $totals[$categoryId]->plus($share)
                    : $share;
            }
        }

        $out = [];

        foreach ($totals as $categoryId => $categoryTotals) {
            $figures = $categoryTotals->toArray();
            /* orderCount is summed per category above; apportion() carries it through unchanged. */
            $figures['orderCount'] = $orderCounts[$categoryId] ?? 0;

            $out[] = [
                'key' => (string) $categoryId,
                'label' => $labels[$categoryId] ?? 'Uncategorised',
                'apportioned' => true,
                ...$figures,
            ];
        }

        return $this->sortByGmv($out);
    }

    /**
     * The window's figures for every order that moved money in it.
     *
     * @return array<int, FinanceTotals>
     */
    private function totalsPerOrder(MetricWindow $window): array
    {
        $folded = [];

        foreach ($this->movementRows($window) as $row) {
            if ($row->referenceId === null) {
                continue;
            }

            $folded[$row->referenceId] ??= ['matrix' => [], 'orders' => []];
            $this->foldRow($row, $folded[$row->referenceId]);
        }

        return array_map(
            fn (array $bucket): FinanceTotals => $this->totalsFrom($bucket['matrix'], count($bucket['orders'])),
            $folded,
        );
    }

    /**
     * What each order was made of, by category, in ngwee of goods.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, array<int, array{label: string, weight: int}>>
     */
    private function categoryWeights(array $orderIds): array
    {
        $rows = DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('order_items.order_id', $orderIds)
            ->groupBy('order_items.order_id', 'categories.id', 'categories.name')
            ->select(
                'order_items.order_id',
                'categories.id as category_id',
                'categories.name as category_name',
                DB::raw('SUM(order_items.total_ngwee) as weight'),
            )
            ->get();

        $weights = [];

        foreach ($rows as $row) {
            $weights[(int) $row->order_id][(int) $row->category_id] = [
                'label' => (string) $row->category_name,
                'weight' => (int) $row->weight,
            ];
        }

        return $weights;
    }

    /**
     * Turn a matrix of account/recipe/direction sums into named figures.
     *
     * Every line below is one metric's definition, written out rather than
     * generated so each can be read against the posting recipe it comes from.
     *
     * @param  array<string, array<string, array<string, int>>>  $matrix
     */
    private function totalsFrom(array $matrix, int $orderCount): FinanceTotals
    {
        /**
         * @param  array<int, string>|null  $recipes
         */
        $sum = static function (LedgerAccountCode $code, EntryDirection $direction, ?array $recipes = null) use ($matrix): Money {
            $total = 0;

            foreach ($matrix[$code->value] ?? [] as $recipe => $directions) {
                if ($recipes !== null && ! in_array((string) $recipe, $recipes, true)) {
                    continue;
                }

                $total += (int) ($directions[$direction->value] ?? 0);
            }

            return Money::ofNgwee($total);
        };

        /* A revenue account earned its credits, less anything reversed back out. */
        $revenue = static fn (LedgerAccountCode $code): Money => $sum($code, EntryDirection::Credit)
            ->minus($sum($code, EntryDirection::Debit));

        /* An expense account cost its debits, less anything credited back. */
        $expense = static fn (LedgerAccountCode $code): Money => $sum($code, EntryDirection::Debit)
            ->minus($sum($code, EntryDirection::Credit));

        return new FinanceTotals(
            /* Cash in, on the two recipes by which a buyer's money arrives. */
            gmv: $sum(LedgerAccountCode::PlatformCash, EntryDirection::Debit, self::PAYMENT_RECIPES),
            orderCount: $orderCount,
            commission: $revenue(LedgerAccountCode::CommissionRevenue),
            addonFees: $revenue(LedgerAccountCode::AddonRevenue),
            referralFees: $revenue(LedgerAccountCode::ReferralRevenue),
            vatOnCommission: $revenue(LedgerAccountCode::VatOnCommissionPayable),
            /* Cash out, on the three recipes by which it goes back to a buyer. */
            refundsToBuyers: $sum(LedgerAccountCode::PlatformCash, EntryDirection::Credit, self::REFUND_RECIPES),
            refundsAbsorbed: $expense(LedgerAccountCode::RefundsExpense),
            lencoFees: $expense(LedgerAccountCode::LencoFeesExpense),
        );
    }

    /**
     * Fold a set of rows into one matrix and one set of order ids.
     *
     * @param  Collection<int, MovementRow>  $rows
     * @return array{matrix: array<string, array<string, array<string, int>>>, orders: array<int, true>}
     */
    private function fold(Collection $rows): array
    {
        $bucket = ['matrix' => [], 'orders' => []];

        foreach ($rows as $row) {
            $this->foldRow($row, $bucket);
        }

        return $bucket;
    }

    /**
     * Add one row to a bucket.
     *
     * Orders are collected as a SET rather than counted, because a single
     * payment entry has several lines and each arrives here separately —
     * counting rows would report one order as four.
     *
     * @param  array{matrix: array<string, array<string, array<string, int>>>, orders: array<int, true>}  $bucket
     */
    private function foldRow(MovementRow $row, array &$bucket): void
    {
        $bucket['matrix'][$row->code][$row->recipe][$row->direction]
            = ($bucket['matrix'][$row->code][$row->recipe][$row->direction] ?? 0) + $row->total;

        if (in_array($row->recipe, self::PAYMENT_RECIPES, true) && $row->referenceId !== null) {
            $bucket['orders'][$row->referenceId] = true;
        }
    }

    /**
     * The join every figure is read through.
     */
    private function baseQuery(): Builder
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id');
    }

    /**
     * Aggregated movement rows for a window.
     *
     * Always grouped by `je.reference_id` so an order can be counted once and
     * a per-order pass is possible without a second trip to the database. The
     * left join to `orders` is what the dimension scopes hang off; entries
     * that reference something else (a payout, an adjustment) keep null there
     * and simply fall outside those breakdowns.
     *
     * @param  Closure(Builder): mixed|null  $scope
     * @return Collection<int, MovementRow>
     */
    private function movementRows(MetricWindow $window, ?Closure $scope = null, bool $withLocalDate = false): Collection
    {
        [$from, $to] = $window->bounds();

        $query = $this->baseQuery()
            ->leftJoin('orders', function ($join): void {
                $join->on('orders.id', '=', 'je.reference_id')
                    ->where('je.reference_type', '=', Order::class);
            })
            ->whereBetween('jl.posted_at', [$from, $to])
            ->groupBy('la.code', 'je.recipe', 'jl.direction', 'je.reference_id')
            ->select('la.code', 'je.recipe', 'jl.direction', 'je.reference_id')
            ->selectRaw('SUM(jl.amount_ngwee) as total');

        if ($withLocalDate) {
            [$expression, $bindings] = $this->localDateExpression();

            $query->selectRaw($expression.' as local_date', $bindings)
                ->groupBy('local_date');
        }

        if ($scope !== null) {
            $scope($query);
        }

        return $query->get()->map(MovementRow::fromDatabase(...));
    }

    /**
     * A SQL expression giving the LOCAL date of a UTC timestamp column.
     *
     * Timestamps are stored in UTC and read by people in Lusaka, so a day
     * boundary falls two hours out unless it is shifted. Zambia has never
     * observed daylight saving, so a fixed offset is exact rather than an
     * approximation — a timezone that did would need the database's own
     * conversion functions and a different method here.
     *
     * The offset is BOUND rather than interpolated. It is derived from config
     * rather than from a request, so injection is not the live risk; binding
     * it keeps the SQL a constant string that is safe to hand to `selectRaw`.
     *
     * That binding is also why the caller groups by the `local_date` ALIAS and
     * never by a second copy of this expression. Laravel runs real prepared
     * statements, so MySQL sees a `?` here rather than a number, and under
     * ONLY_FULL_GROUP_BY it cannot prove that two expressions each holding a
     * separate placeholder are the same one — it rejects the query as having
     * `jl.posted_at` outside the GROUP BY. Grouping by the output name sidesteps
     * the comparison entirely, and SQLite and PostgreSQL accept it too.
     *
     * The column is written out rather than passed in. There is exactly one
     * timestamp these figures are ever bucketed by, and naming it here keeps
     * the expression a compile-time constant — which is what lets it be
     * handed to `selectRaw` as the literal string it expects, with no way for
     * a caller to put anything else in it.
     *
     * @return array{0: literal-string, 1: array<int, string|int>}
     */
    private function localDateExpression(): array
    {
        $minutes = (int) (CarbonImmutable::now(
            (string) config('monafind.display_timezone', 'Africa/Lusaka'),
        )->getOffset() / 60);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => ['date(jl.posted_at, ?)', [sprintf('%+d minutes', $minutes)]],
            'pgsql' => ["date(jl.posted_at + (? || ' minutes')::interval)", [$minutes]],
            default => ['DATE(DATE_ADD(jl.posted_at, INTERVAL ? MINUTE))', [$minutes]],
        };
    }

    /**
     * @param  array<int, array<string, int|string|bool>>  $rows
     * @return array<int, array<string, int|string|bool>>
     */
    private function sortByGmv(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => ((int) ($b['gmvNgwee'] ?? 0)) <=> ((int) ($a['gmvNgwee'] ?? 0)));

        return $rows;
    }

    /**
     * A dimension key as a person reads it.
     *
     * Seller types are stored as short codes ("APS", "CB"), which are fine in
     * a column and useless on a chart legend.
     */
    private function labelFor(string $key, MetricDimension $dimension): string
    {
        if ($key === '') {
            return $dimension === MetricDimension::Province ? 'No province recorded' : 'Not attributable';
        }

        return $dimension === MetricDimension::SellerType
            ? (SellerType::tryFrom($key)?->label() ?? $key)
            : $key;
    }
}
