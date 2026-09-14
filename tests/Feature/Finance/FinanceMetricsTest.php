<?php

declare(strict_types=1);

use App\Modules\Finance\Enums\MetricDimension;
use App\Modules\Finance\Enums\MetricGranularity;
use App\Modules\Finance\Services\FinanceMetrics;
use App\Modules\Finance\Support\MetricWindow;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard against the books.
 *
 * Every assertion here compares a figure the screen would show with a sum
 * taken straight off `journal_lines`. That is the whole contract of the
 * module: if these two ever disagree, the dashboard is wrong, because the
 * ledger cannot be.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->postings = app(OrderPostingService::class);
    $this->metrics = app(FinanceMetrics::class);
});

/**
 * The signed movement on an account, summed directly from the lines.
 */
function ledgerMovement(LedgerAccountCode $code, EntryDirection $direction, ?array $recipes = null): int
{
    return (int) DB::table('journal_lines as jl')
        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
        ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
        ->where('la.code', $code->value)
        ->where('jl.direction', $direction->value)
        ->when($recipes !== null, fn ($query) => $query->whereIn('je.recipe', $recipes))
        ->sum('jl.amount_ngwee');
}

function financeOrder(int $goods = 100_000, int $delivery = 0, bool $direct = false): Order
{
    $factory = Order::factory();

    return ($direct ? $factory->directSettlement() : $factory->paid())
        ->create([
            'items_total_ngwee' => $goods,
            'delivery_fee_ngwee' => $delivery,
            'total_ngwee' => $goods + $delivery,
        ]);
}

/**
 * A date string as the dashboard's readers write one.
 *
 * `MetricWindow` reads its date strings as Lusaka dates, so a window built
 * from UTC ones is two hours short — and between 22:00 and 24:00 UTC it is a
 * whole day out, silently excluding anything posted since local midnight.
 */
function financeLocalDate(int $daysAgo = 0): string
{
    return CarbonImmutable::now((string) config('monafind.display_timezone'))
        ->subDays($daysAgo)
        ->toDateString();
}

it('reports GMV as the cash that arrived on payment recipes', function (): void {
    $escrow = financeOrder(100_000, 5_000);
    $direct = financeOrder(50_000, 0, direct: true);

    $this->postings->recordPayment($escrow);
    $this->postings->recordPayment($direct);

    $totals = $this->metrics->totals(MetricWindow::fromStrings(null, null));

    expect($totals->gmv->ngwee)->toBe(155_000)
        ->and($totals->gmv->ngwee)->toBe(ledgerMovement(
            LedgerAccountCode::PlatformCash,
            EntryDirection::Debit,
            ['escrow_payment', 'direct_payment'],
        ))
        ->and($totals->orderCount)->toBe(2);
});

it('counts an order once however many lines its entry has', function (): void {
    /* A direct payment posts six lines for one order. */
    $this->postings->recordPayment(financeOrder(100_000, 0, direct: true));

    expect($this->metrics->totals(MetricWindow::fromStrings(null, null))->orderCount)->toBe(1);
});

it('recognises no revenue on an escrow payment and all of it on release', function (): void {
    $order = financeOrder(100_000);
    $this->postings->recordPayment($order);

    $window = MetricWindow::fromStrings(null, null);

    expect($this->metrics->totals($window)->commission->ngwee)->toBe(0);

    $this->postings->releaseEscrow($order);

    $totals = $this->metrics->totals($window);

    /* 7.5% of K1,000.00, and 16% VAT on that commission. */
    expect($totals->commission->ngwee)->toBe(7_500)
        ->and($totals->vatOnCommission->ngwee)->toBe(1_200)
        ->and($totals->commission->ngwee)->toBe(ledgerMovement(
            LedgerAccountCode::CommissionRevenue,
            EntryDirection::Credit,
        ));
});

it('keeps VAT out of net revenue because it is owed to ZRA', function (): void {
    $order = financeOrder(100_000);
    $this->postings->recordPayment($order);
    $this->postings->releaseEscrow($order);

    $totals = $this->metrics->totals(MetricWindow::fromStrings(null, null));

    expect($totals->netRevenue()->ngwee)->toBe($totals->grossRevenue()->ngwee)
        ->and($totals->netRevenue()->ngwee)->toBe(7_500)
        ->and($totals->vatOnCommission->ngwee)->toBe(1_200);
});

it('reports positions that match the ledger balances', function (): void {
    $held = financeOrder(100_000);
    $released = financeOrder(60_000);

    $this->postings->recordPayment($held);
    $this->postings->recordPayment($released);
    $this->postings->releaseEscrow($released);

    $positions = $this->metrics->positions(MetricWindow::fromStrings(null, null));
    $balances = app(LedgerBalances::class);

    expect($positions->escrowHeld->ngwee)->toBe($balances->forAccount(LedgerAccountCode::EscrowHeld)->ngwee)
        ->and($positions->escrowHeld->ngwee)->toBe(100_000)
        ->and($positions->payablesOutstanding->ngwee)
        ->toBe($balances->forAccount(LedgerAccountCode::SellerPayable)->ngwee)
        ->and($positions->platformCash->ngwee)
        ->toBe($balances->forAccount(LedgerAccountCode::PlatformCash)->ngwee);
});

it('fills empty buckets rather than closing up the gap', function (): void {
    $order = financeOrder(100_000);
    $this->postings->recordPayment($order);

    $series = $this->metrics->series(
        MetricWindow::fromStrings(financeLocalDate(4), financeLocalDate()),
        MetricGranularity::Day,
    );

    expect($series)->toHaveCount(5)
        ->and(array_sum(array_column($series, 'gmvNgwee')))->toBe(100_000);
});

it('breaks down by seller type without losing a ngwee', function (): void {
    $this->postings->recordPayment(financeOrder(100_000));
    $this->postings->recordPayment(financeOrder(40_000));

    $window = MetricWindow::fromStrings(null, null);
    $rows = $this->metrics->breakdown($window, MetricDimension::SellerType);

    expect(array_sum(array_column($rows, 'gmvNgwee')))
        ->toBe($this->metrics->totals($window)->gmv->ngwee);
});

/**
 * A guard on the shape of the bucketed query, not on its figures.
 *
 * The suite runs on SQLite, which happily matches a GROUP BY expression to an
 * identical one in the SELECT list even when both carry a placeholder. MySQL
 * under ONLY_FULL_GROUP_BY does not: it sees two separate `?` parameters,
 * cannot prove the expressions are the same, and rejects the query with
 * "'jl.posted_at' isn't in GROUP BY". So the day bucket must be grouped by the
 * `local_date` ALIAS, and the offset expression must appear exactly once.
 */
it('groups the day bucket by the local_date alias so MySQL can run it', function (): void {
    $this->postings->recordPayment(financeOrder(100_000));

    DB::enableQueryLog();

    $this->metrics->series(
        MetricWindow::fromStrings(financeLocalDate(1), financeLocalDate()),
        MetricGranularity::Day,
    );

    $bucketed = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $sql): bool => str_contains($sql, 'as local_date'));

    DB::disableQueryLog();

    expect($bucketed)->not->toBeNull();

    [, $groupBy] = explode('group by', (string) $bucketed, 2);

    expect($groupBy)->toContain('local_date')
        ->and(substr_count((string) $bucketed, 'jl.posted_at, '))->toBe(1);
});
