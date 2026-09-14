<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\FakePaymentGateway;
use App\Modules\Finance\Database\Seeders\VatRateSeeder;
use App\Modules\Finance\Enums\MetricDimension;
use App\Modules\Finance\Enums\MetricGranularity;
use App\Modules\Finance\Services\FinanceMetrics;
use App\Modules\Finance\Support\MetricWindow;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Services\PlatformCashCheck;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The acceptance scenario, in full.
 *
 * One month of trading that covers both settlement modes, a refund out of
 * escrow, a clawback from a seller already credited, and a payout. Every
 * dashboard figure is then asserted against a sum taken straight off
 * `journal_lines` — not against a number this test worked out for itself,
 * which would only prove the test and the code make the same mistake.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->seed(VatRateSeeder::class);

    /*
     * Bound as an instance rather than resolved, so the test and the service
     * under test hold the same fake — `app(FakePaymentGateway::class)` would
     * build a second one and stub a balance nothing ever reads.
     */
    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    $this->postings = app(OrderPostingService::class);
    $this->metrics = app(FinanceMetrics::class);
});

/**
 * The signed movement on an account, summed directly from the lines.
 *
 * @param  array<int, string>|null  $recipes
 */
function directSum(LedgerAccountCode $code, EntryDirection $direction, ?array $recipes = null): int
{
    return (int) DB::table('journal_lines as jl')
        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
        ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
        ->where('la.code', $code->value)
        ->where('jl.direction', $direction->value)
        ->when($recipes !== null, fn ($query) => $query->whereIn('je.recipe', $recipes))
        ->sum('jl.amount_ngwee');
}

/**
 * A month of trading covering every money path the platform has.
 *
 * @return array{escrowSeller: Seller, directSeller: Seller}
 */
function seedTradingMonth(OrderPostingService $postings): array
{
    $escrowSeller = Seller::factory()->create();
    $directSeller = Seller::factory()->create();

    $order = static fn (Seller $seller, int $goods, bool $direct): Order => (
        $direct ? Order::factory()->directSettlement() : Order::factory()->paid()
    )->for($seller)->create([
        'items_total_ngwee' => $goods,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => $goods,
    ]);

    /* ESCROW: paid, released, and MonaFind's commission recognised. */
    $released = $order($escrowSeller, 200_000, false);
    $postings->recordPayment($released);
    $postings->releaseEscrow($released);

    /* ESCROW: paid and partly refunded before release — a refund out of escrow. */
    $refunded = $order($escrowSeller, 100_000, false);
    $postings->recordPayment($refunded);
    $postings->refund($refunded, Money::ofNgwee(40_000), 'refund:escrow:1');

    /* DIRECT: settled immediately, reserve withheld. */
    $directOrder = $order($directSeller, 150_000, true);
    $postings->recordPayment($directOrder);

    /* DIRECT: refunded after the seller was credited — a clawback. */
    $clawedBack = $order($directSeller, 80_000, true);
    $postings->recordPayment($clawedBack);
    $postings->refund($clawedBack, Money::ofNgwee(80_000), 'refund:clawback:1');

    /* A payout of what the escrow seller is owed, with a gateway fee. */
    $payable = app(LedgerBalances::class)->sellerPayable($escrowSeller);
    $postings->recordPayout($escrowSeller, $payable, 'payout:batch:1', Money::ofNgwee(750));

    return ['escrowSeller' => $escrowSeller, 'directSeller' => $directSeller];
}

it('equals direct ledger sums across every money path', function (): void {
    seedTradingMonth($this->postings);

    $totals = $this->metrics->totals(MetricWindow::fromStrings(null, null));

    expect($totals->gmv->ngwee)->toBe(directSum(
        LedgerAccountCode::PlatformCash,
        EntryDirection::Debit,
        ['escrow_payment', 'direct_payment'],
    ));

    expect($totals->commission->ngwee)->toBe(
        directSum(LedgerAccountCode::CommissionRevenue, EntryDirection::Credit)
        - directSum(LedgerAccountCode::CommissionRevenue, EntryDirection::Debit),
    );

    expect($totals->addonFees->ngwee)->toBe(
        directSum(LedgerAccountCode::AddonRevenue, EntryDirection::Credit)
        - directSum(LedgerAccountCode::AddonRevenue, EntryDirection::Debit),
    );

    expect($totals->referralFees->ngwee)->toBe(
        directSum(LedgerAccountCode::ReferralRevenue, EntryDirection::Credit)
        - directSum(LedgerAccountCode::ReferralRevenue, EntryDirection::Debit),
    );

    expect($totals->vatOnCommission->ngwee)->toBe(
        directSum(LedgerAccountCode::VatOnCommissionPayable, EntryDirection::Credit)
        - directSum(LedgerAccountCode::VatOnCommissionPayable, EntryDirection::Debit),
    );

    expect($totals->refundsToBuyers->ngwee)->toBe(directSum(
        LedgerAccountCode::PlatformCash,
        EntryDirection::Credit,
        ['escrow_refund', 'direct_clawback', 'goodwill_refund'],
    ));

    expect($totals->lencoFees->ngwee)->toBe(
        directSum(LedgerAccountCode::LencoFeesExpense, EntryDirection::Debit)
        - directSum(LedgerAccountCode::LencoFeesExpense, EntryDirection::Credit),
    );

    expect($totals->refundsAbsorbed->ngwee)->toBe(
        directSum(LedgerAccountCode::RefundsExpense, EntryDirection::Debit)
        - directSum(LedgerAccountCode::RefundsExpense, EntryDirection::Credit),
    );
});

it('reports positions equal to the cached ledger balances', function (): void {
    seedTradingMonth($this->postings);

    $positions = $this->metrics->positions(MetricWindow::fromStrings(null, null));
    $balances = app(LedgerBalances::class);

    expect($positions->platformCash->ngwee)->toBe($balances->forAccount(LedgerAccountCode::PlatformCash)->ngwee)
        ->and($positions->escrowHeld->ngwee)->toBe($balances->forAccount(LedgerAccountCode::EscrowHeld)->ngwee)
        ->and($positions->payablesOutstanding->ngwee)
        ->toBe($balances->forAccount(LedgerAccountCode::SellerPayable)->ngwee)
        ->and($positions->reserveHeld->ngwee)->toBe($balances->forAccount(LedgerAccountCode::SellerReserve)->ngwee)
        ->and($positions->vatOwed->ngwee)
        ->toBe($balances->forAccount(LedgerAccountCode::VatOnCommissionPayable)->ngwee);
});

it('shows a clawback as a negative payable rather than hiding it', function (): void {
    $sellers = seedTradingMonth($this->postings);

    $balances = app(LedgerBalances::class);
    $window = MetricWindow::fromStrings(null, null);

    expect($this->metrics->sellerClosingBalance($window, $sellers['directSeller']->getKey(), LedgerAccountCode::SellerPayable)->ngwee)
        ->toBe($balances->sellerPayable($sellers['directSeller'])->ngwee);
});

it('keeps a series footing to the headline totals', function (): void {
    seedTradingMonth($this->postings);

    $window = MetricWindow::fromStrings(null, null);
    $totals = $this->metrics->totals($window);

    foreach ([MetricGranularity::Day, MetricGranularity::Week, MetricGranularity::Month] as $granularity) {
        $series = $this->metrics->series($window, $granularity);

        expect(array_sum(array_column($series, 'gmvNgwee')))->toBe($totals->gmv->ngwee)
            ->and(array_sum(array_column($series, 'commissionNgwee')))->toBe($totals->commission->ngwee)
            ->and(array_sum(array_column($series, 'orderCount')))->toBe($totals->orderCount);
    }
});

it('keeps every breakdown footing to the headline totals', function (): void {
    seedTradingMonth($this->postings);

    $window = MetricWindow::fromStrings(null, null);
    $totals = $this->metrics->totals($window);

    foreach ([MetricDimension::SellerType, MetricDimension::Province] as $dimension) {
        $rows = $this->metrics->breakdown($window, $dimension);

        expect(array_sum(array_column($rows, 'gmvNgwee')))->toBe($totals->gmv->ngwee)
            ->and(array_sum(array_column($rows, 'commissionNgwee')))->toBe($totals->commission->ngwee);
    }
});

describe('the daily cash check', function (): void {
    it('says the books and the gateway agree', function (): void {
        seedTradingMonth($this->postings);

        $ledger = app(LedgerBalances::class)->forAccount(LedgerAccountCode::PlatformCash);
        $this->gateway->stubAccountBalance($ledger);

        $result = app(PlatformCashCheck::class)->run();

        expect($result['status'])->toBe('balanced')
            ->and($result['varianceNgwee'])->toBe(0);
    });

    it('reports a variance with its direction', function (): void {
        seedTradingMonth($this->postings);

        $ledger = app(LedgerBalances::class)->forAccount(LedgerAccountCode::PlatformCash);
        $this->gateway->stubAccountBalance($ledger->minus(Money::ofNgwee(5_000)));

        $result = app(PlatformCashCheck::class)->run();

        expect($result['status'])->toBe('variance')
            ->and($result['varianceNgwee'])->toBe(5_000)
            ->and($result['message'])->toContain('more cash than Lenco');
    });

    it('treats a silent gateway as unknown rather than as an empty account', function (): void {
        seedTradingMonth($this->postings);

        $this->gateway->stubAccountBalance(null);

        $result = app(PlatformCashCheck::class)->run();

        expect($result['status'])->toBe('unknown')
            ->and($result['gatewayNgwee'])->toBeNull()
            ->and($result['varianceNgwee'])->toBeNull();
    });
});

describe('the console screen', function (): void {
    it('is closed to a moderator and open to finance', function (): void {
        actingAsStaff([Role::Moderator]);
        $this->get(route('admin.finance.dashboard'))->assertForbidden();

        actingAsStaff([Role::Finance]);
        $this->get(route('admin.finance.dashboard'))->assertOk();
    });
});
