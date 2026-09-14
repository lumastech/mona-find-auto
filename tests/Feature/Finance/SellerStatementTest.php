<?php

declare(strict_types=1);

use App\Modules\Finance\Database\Seeders\VatRateSeeder;
use App\Modules\Finance\Models\SellerStatement;
use App\Modules\Finance\Services\CommissionInvoiceDocumentService;
use App\Modules\Finance\Services\SellerStatementService;
use App\Modules\Finance\Services\StatementDocumentService;
use App\Modules\Ledger\Models\CommissionInvoice;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;

/**
 * A seller's month, and the documents it produces.
 *
 * The figures on a statement have to reconcile to the ledger — that is the
 * acceptance criterion — and they have to STOP moving once the month is
 * closed, which is the harder of the two and the one the last tests cover.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->seed(VatRateSeeder::class);

    $this->postings = app(OrderPostingService::class);
    $this->statements = app(SellerStatementService::class);
});

/**
 * A paid order for a given seller.
 */
function statementOrder(Seller $seller, int $goods, bool $direct = false): Order
{
    $factory = Order::factory();

    return ($direct ? $factory->directSettlement() : $factory->paid())
        ->for($seller)
        ->create([
            'items_total_ngwee' => $goods,
            'delivery_fee_ngwee' => 0,
            'total_ngwee' => $goods,
        ]);
}

it('reconciles a closed month to the ledger', function (): void {
    $seller = Seller::factory()->create();

    $first = statementOrder($seller, 100_000);
    $second = statementOrder($seller, 60_000);

    $this->postings->recordPayment($first);
    $this->postings->recordPayment($second);
    $this->postings->releaseEscrow($first);
    $this->postings->releaseEscrow($second);

    $statement = $this->statements->generate($seller, now());
    $balances = app(LedgerBalances::class);

    expect($statement->sales_ngwee->ngwee)->toBe(160_000)
        ->and($statement->order_count)->toBe(2)
        /* 7.5% commission, 16% VAT on that. */
        ->and($statement->commission_ngwee->ngwee)->toBe(12_000)
        ->and($statement->vat_on_commission_ngwee->ngwee)->toBe(1_920)
        /* The closing payable is the seller's ledger balance, to the ngwee. */
        ->and($statement->closing_payable_ngwee->ngwee)
        ->toBe($balances->sellerPayable($seller)->ngwee)
        ->and($statement->closing_payable_ngwee->ngwee)->toBe(160_000 - 12_000 - 1_920);
});

it('counts a payout against the month it settled in', function (): void {
    $seller = Seller::factory()->create();
    $order = statementOrder($seller, 100_000);

    $this->postings->recordPayment($order);
    $this->postings->releaseEscrow($order);

    $payable = app(LedgerBalances::class)->sellerPayable($seller);
    $this->postings->recordPayout($seller, $payable, 'payout:test:1', money(500));

    $statement = $this->statements->generate($seller, now());

    expect($statement->payouts_ngwee->ngwee)->toBe($payable->ngwee)
        ->and($statement->closing_payable_ngwee->ngwee)->toBe(0);
});

it('shows a refund against the seller whose order it came out of', function (): void {
    $seller = Seller::factory()->create();
    $order = statementOrder($seller, 100_000);

    $this->postings->recordPayment($order);
    $this->postings->refund($order, money(30_000), 'refund:test:1');

    $statement = $this->statements->generate($seller, now());

    expect($statement->refunds_ngwee->ngwee)->toBe(30_000)
        ->and($statement->sales_ngwee->ngwee)->toBe(100_000);
});

it('withholds a reserve from a direct-settlement seller', function (): void {
    $seller = Seller::factory()->create();
    $order = statementOrder($seller, 100_000, direct: true);

    $this->postings->recordPayment($order);

    $statement = $this->statements->generate($seller, now());

    /* The default reserve is 10% of the gross. */
    expect($statement->reserve_withheld_ngwee->ngwee)->toBe(10_000)
        ->and($statement->closing_reserve_ngwee->ngwee)->toBe(10_000);
});

it('gives a month back untouched once it has closed', function (): void {
    $seller = Seller::factory()->create();
    $order = statementOrder($seller, 100_000);
    $this->postings->recordPayment($order);

    $first = $this->statements->generate($seller, now());

    /* More money moves after the month closed. */
    $later = statementOrder($seller, 500_000);
    $this->postings->recordPayment($later);

    $second = $this->statements->generate($seller, now());

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->sales_ngwee->ngwee)->toBe($first->sales_ngwee->ngwee)
        ->and(SellerStatement::query()->count())->toBe(1);
});

it('rebuilds a closed month only when explicitly asked, and audits it', function (): void {
    $seller = Seller::factory()->create();
    $this->postings->recordPayment(statementOrder($seller, 100_000));

    $original = $this->statements->generate($seller, now());

    $this->postings->recordPayment(statementOrder($seller, 40_000));
    $rebuilt = $this->statements->regenerate($seller, now(), 'Backdated adjustment.');

    expect($rebuilt->sales_ngwee->ngwee)->toBe(140_000)
        ->and($original->sales_ngwee->ngwee)->toBe(100_000)
        ->and(SellerStatement::query()->count())->toBe(1);

    $this->assertDatabaseHas('audit_logs', ['action' => 'seller_statement.regenerated']);
});

it('offers no statement to a seller who did not trade', function (): void {
    Seller::factory()->create();
    $trader = Seller::factory()->create();

    $this->postings->recordPayment(statementOrder($trader, 100_000));

    expect($this->statements->sellersWithActivityIn(now()))->toBe([$trader->getKey()]);
});

describe('documents', function (): void {
    it('renders a statement as a PDF and a CSV from the same closed row', function (): void {
        $seller = Seller::factory()->create();
        $this->postings->recordPayment(statementOrder($seller, 100_000));

        $statement = $this->statements->generate($seller, now());
        $documents = app(StatementDocumentService::class);

        $pdf = $documents->pdf($statement);
        $csv = $documents->csv($statement);

        expect($pdf)->toStartWith('%PDF-')
            ->and($csv)->toContain('Gross sales (VAT-inclusive)')
            ->and($csv)->toContain('1000.00');
    });

    it('says on the statement that goods VAT is the seller\'s own', function (): void {
        $seller = Seller::factory()->create();
        $this->postings->recordPayment(statementOrder($seller, 100_000));

        $pdf = app(StatementDocumentService::class)->pdf($this->statements->generate($seller, now()));

        expect($pdf)->toContain('VAT-inclusive')
            ->and($pdf)->toContain('your own')
            ->and($pdf)->toContain('not a tax invoice');
    });

    it('invoices the commission only, never the order', function (): void {
        $seller = Seller::factory()->create();
        $order = statementOrder($seller, 100_000);

        $this->postings->recordPayment($order);
        $this->postings->releaseEscrow($order);

        $invoice = CommissionInvoice::query()->firstOrFail();
        $pdf = app(CommissionInvoiceDocumentService::class)->pdf($invoice);

        expect($pdf)->toStartWith('%PDF-')
            ->and($pdf)->toContain($invoice->number)
            ->and($pdf)->toContain('VAT on commission')
            /* The invoice total is commission plus its VAT — not the order. */
            ->and($invoice->total_ngwee->ngwee)->toBe(7_500 + 1_200)
            ->and($invoice->total_ngwee->ngwee)->not->toBe($order->total_ngwee->ngwee);
    });

    it('numbers invoices gaplessly within a year', function (): void {
        $seller = Seller::factory()->create();

        foreach (range(1, 4) as $ignored) {
            $order = statementOrder($seller, 50_000);
            $this->postings->recordPayment($order);
            $this->postings->releaseEscrow($order);
        }

        $numbers = CommissionInvoice::query()->orderBy('series_number')->pluck('series_number')->all();

        expect($numbers)->toBe([1, 2, 3, 4])
            ->and(CommissionInvoice::query()->orderBy('id')->value('number'))
            ->toBe(sprintf('MFA-INV-%d-00001', (int) now()->format('Y')));
    });
});

describe('the seller portal', function (): void {
    it('lets a seller download their own statement but not another shop\'s', function (): void {
        $user = actingAsStaff([Role::Seller]);
        $mine = Seller::factory()->for($user)->create();
        $theirs = Seller::factory()->create();

        $this->postings->recordPayment(statementOrder($mine, 100_000));
        $this->postings->recordPayment(statementOrder($theirs, 100_000));

        $ours = $this->statements->generate($mine, now());
        $notOurs = $this->statements->generate($theirs, now());

        $this->get(route('seller.statements.download', ['statement' => $ours, 'format' => 'pdf']))
            ->assertOk();

        $this->get(route('seller.statements.download', ['statement' => $notOurs, 'format' => 'pdf']))
            ->assertNotFound();
    });
});
