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
        MetricWindow::fromStrings(now()->subDays(4)->toDateString(), now()->toDateString()),
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
