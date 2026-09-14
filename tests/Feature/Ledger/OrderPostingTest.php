<?php

declare(strict_types=1);

use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Models\CommissionInvoice;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Support\Money\Money;
use Database\Seeders\SettingsSeeder;

/**
 * Every recipe, in both modes, asserted line by line.
 *
 * The platform's defaults are 7.5% commission and 16% VAT on it, so a
 * K1,000.00 order of goods carries K75.00 commission and K12.00 VAT, leaving
 * K913.00 for the seller. Those figures recur throughout.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->postings = app(OrderPostingService::class);
    $this->balances = app(LedgerBalances::class);
});

/**
 * A paid order worth $goods plus $delivery, in the given settlement mode.
 */
function ledgerOrder(int $goods = 100_000, int $delivery = 0, bool $direct = false): Order
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
 * The amount posted to an account by one entry, whichever side it landed on.
 */
function lineOn(JournalEntry $entry, LedgerAccountCode $code): ?int
{
    /*
     * loadMissing rather than a bare read: the entry comes back from the
     * posting service with its lines but not their accounts, and
     * Model::preventLazyLoading() turns an implicit load into a failure. One
     * explicit load here is also one query instead of one per line.
     */
    $entry->loadMissing('lines.account');

    $line = $entry->lines->first(
        static fn ($line): bool => $line->account->code === $code,
    );

    return $line?->amount_ngwee->ngwee;
}

describe('escrow', function (): void {
    it('holds the buyer\'s money and touches no revenue', function (): void {
        $order = ledgerOrder(100_000, 5_000);

        $entry = $this->postings->recordPayment($order);

        expect($entry->recipe)->toBe(PostingRecipe::EscrowPayment)
            ->and($entry->lines)->toHaveCount(2)
            ->and(lineOn($entry, LedgerAccountCode::PlatformCash))->toBe(105_000)
            ->and(lineOn($entry, LedgerAccountCode::EscrowHeld))->toBe(105_000)
            ->and($this->balances->escrowHeldFor($order))->toBeMoney(105_000)
            /* Nothing has been earned yet, and an order refunded tomorrow never will be. */
            ->and($this->balances->forAccount(LedgerAccountCode::CommissionRevenue))->toBeMoney(0)
            ->and($this->balances->sellerPayable($order->seller))->toBeMoney(0)
            ->and(CommissionInvoice::query()->count())->toBe(0);
    });

    it('splits escrow into payable and revenue on release', function (): void {
        $order = ledgerOrder(100_000, 5_000);
        $this->postings->recordPayment($order);

        $entry = $this->postings->releaseEscrow($order);

        expect($entry->recipe)->toBe(PostingRecipe::EscrowRelease)
            ->and(lineOn($entry, LedgerAccountCode::EscrowHeld))->toBe(105_000)
            ->and(lineOn($entry, LedgerAccountCode::SellerPayable))->toBe(96_300)
            ->and(lineOn($entry, LedgerAccountCode::CommissionRevenue))->toBe(7_500)
            ->and(lineOn($entry, LedgerAccountCode::VatOnCommissionPayable))->toBe(1_200)
            /* Escrow is emptied, and no reserve is withheld on this mode. */
            ->and($this->balances->escrowHeldFor($order))->toBeMoney(0)
            ->and($this->balances->sellerReserve($order->seller))->toBeMoney(0)
            ->and($this->balances->sellerPayable($order->seller))->toBeMoney(96_300);
    });

    it('raises one commission invoice, at release and not before', function (): void {
        $order = ledgerOrder(100_000);
        $this->postings->recordPayment($order);

        expect(CommissionInvoice::query()->count())->toBe(0);

        $this->postings->releaseEscrow($order);

        $invoice = CommissionInvoice::query()->sole();

        expect($invoice->commission_ngwee)->toBeMoney(7_500)
            ->and($invoice->vat_ngwee)->toBeMoney(1_200)
            /* Commission plus its VAT — never the order. */
            ->and($invoice->total_ngwee)->toBeMoney(8_700)
            ->and($invoice->vat_rate_percent)->toBe('16.00')
            ->and($invoice->number)->toStartWith('MFA-INV-');

        /* Releasing again changes nothing. */
        $this->postings->releaseEscrow($order);
        expect(CommissionInvoice::query()->count())->toBe(1);
    });

    it('releases nothing when nothing is held', function (): void {
        $order = ledgerOrder(100_000);

        expect($this->postings->releaseEscrow($order))->toBeNull()
            ->and(JournalEntry::query()->count())->toBe(0);
    });
});

describe('direct settlement', function (): void {
    it('splits at payment and withholds the reserve', function (): void {
        $order = ledgerOrder(100_000, direct: true);

        $entry = $this->postings->recordPayment($order);

        expect($entry->recipe)->toBe(PostingRecipe::DirectPayment)
            ->and(lineOn($entry, LedgerAccountCode::PlatformCash))->toBe(100_000)
            /* Net 91_300, less the 10% reserve. */
            ->and(lineOn($entry, LedgerAccountCode::SellerPayable))->toBe(81_300)
            ->and(lineOn($entry, LedgerAccountCode::SellerReserve))->toBe(10_000)
            ->and(lineOn($entry, LedgerAccountCode::CommissionRevenue))->toBe(7_500)
            ->and(lineOn($entry, LedgerAccountCode::VatOnCommissionPayable))->toBe(1_200)
            /* There is no escrow leg at all on this mode. */
            ->and($this->balances->escrowHeldFor($order))->toBeMoney(0);
    });

    it('invoices the commission at payment, because it was earned there', function (): void {
        $order = ledgerOrder(100_000, direct: true);

        $this->postings->recordPayment($order);

        expect(CommissionInvoice::query()->sole()->total_ngwee)->toBeMoney(8_700);
    });

    it('has nothing to release on completion', function (): void {
        $order = ledgerOrder(100_000, direct: true);
        $this->postings->recordPayment($order);

        expect($this->postings->releaseEscrow($order))->toBeNull()
            ->and(JournalEntry::query()->count())->toBe(1);
    });
});

describe('refunds', function (): void {
    it('takes a full escrow refund straight back out of escrow', function (): void {
        $order = ledgerOrder(100_000, 5_000);
        $this->postings->recordPayment($order);

        $entries = $this->postings->refund($order, Money::ofNgwee(105_000), 'dispute:1');

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->recipe)->toBe(PostingRecipe::EscrowRefund)
            ->and(lineOn($entries[0], LedgerAccountCode::EscrowHeld))->toBe(105_000)
            ->and(lineOn($entries[0], LedgerAccountCode::PlatformCash))->toBe(105_000)
            ->and($this->balances->escrowHeldFor($order))->toBeMoney(0)
            /* No revenue was ever recognised, so none has to be reversed. */
            ->and($this->balances->forAccount(LedgerAccountCode::CommissionRevenue))->toBeMoney(0)
            ->and($this->balances->forAccount(LedgerAccountCode::PlatformCash))->toBeMoney(0);
    });

    it('charges commission on what the buyer kept after a partial refund', function (): void {
        $order = ledgerOrder(100_000);
        $this->postings->recordPayment($order);

        $this->postings->refund($order, Money::ofNgwee(40_000), 'dispute:2');

        expect($this->balances->escrowHeldFor($order))->toBeMoney(60_000);

        $release = $this->postings->releaseEscrow($order);

        /* 7.5% of the retained 60_000, not of the original 100_000. */
        expect(lineOn($release, LedgerAccountCode::CommissionRevenue))->toBe(4_500)
            ->and(lineOn($release, LedgerAccountCode::VatOnCommissionPayable))->toBe(720)
            ->and(lineOn($release, LedgerAccountCode::SellerPayable))->toBe(54_780)
            ->and($this->balances->escrowHeldFor($order))->toBeMoney(0);
    });

    it('claws a refund back from a direct seller in proportion', function (): void {
        $order = ledgerOrder(100_000, direct: true);
        $this->postings->recordPayment($order);

        $entries = $this->postings->refund($order, Money::ofNgwee(50_000), 'dispute:3');

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->recipe)->toBe(PostingRecipe::DirectClawback);

        /* Everybody who was paid gives back exactly half. */
        expect($this->balances->sellerPayable($order->seller))->toBeMoney(40_650)
            ->and($this->balances->sellerReserve($order->seller))->toBeMoney(5_000)
            ->and($this->balances->forAccount(LedgerAccountCode::CommissionRevenue))->toBeMoney(3_750)
            ->and($this->balances->forAccount(LedgerAccountCode::VatOnCommissionPayable))->toBeMoney(600)
            ->and($this->balances->forAccount(LedgerAccountCode::PlatformCash))->toBeMoney(50_000);
    });

    it('drives a payable negative when the seller has already been paid', function (): void {
        $order = ledgerOrder(100_000, direct: true);
        $seller = $order->seller;
        $this->postings->recordPayment($order);

        /* The seller withdraws everything they were owed. */
        $this->postings->recordPayout($seller, Money::ofNgwee(81_300), 'payout:1');
        expect($this->balances->sellerPayable($seller))->toBeMoney(0);

        $this->postings->refund($order, Money::ofNgwee(100_000), 'dispute:4');

        /* They now owe the platform, which nets off against their next sales. */
        expect($this->balances->sellerPayable($seller))->toBeMoney(-81_300)
            ->and($this->balances->sellerReserve($seller))->toBeMoney(0);
    });

    it('claws back an escrow order refunded after release', function (): void {
        $order = ledgerOrder(100_000);
        $this->postings->recordPayment($order);
        $this->postings->releaseEscrow($order);

        $entries = $this->postings->refund($order, Money::ofNgwee(100_000), 'dispute:5');

        expect($entries[0]->recipe)->toBe(PostingRecipe::DirectClawback)
            ->and($this->balances->sellerPayable($order->seller))->toBeMoney(0)
            ->and($this->balances->forAccount(LedgerAccountCode::CommissionRevenue))->toBeMoney(0);
    });

    it('never refunds more than the order was worth', function (): void {
        $order = ledgerOrder(100_000);
        $this->postings->recordPayment($order);

        $this->postings->refund($order, Money::ofNgwee(80_000), 'dispute:6');
        $this->postings->refund($order, Money::ofNgwee(80_000), 'dispute:7');

        expect($this->balances->forAccount(LedgerAccountCode::PlatformCash))->toBeMoney(0)
            ->and($this->balances->escrowHeldFor($order))->toBeMoney(0);
    });

    it('posts a goodwill refund against the platform\'s own pocket', function (): void {
        $order = ledgerOrder(100_000, direct: true);
        $this->postings->recordPayment($order);

        $entry = $this->postings->postGoodwillRefund($order, Money::ofNgwee(5_000), 'goodwill:1');

        expect(lineOn($entry, LedgerAccountCode::RefundsExpense))->toBe(5_000)
            /* The seller keeps everything; the platform absorbs it. */
            ->and($this->balances->sellerPayable($order->seller))->toBeMoney(81_300)
            ->and($this->balances->forAccount(LedgerAccountCode::RefundsExpense))->toBeMoney(5_000);
    });
});

describe('payouts and reserves', function (): void {
    it('charges the gateway fee to the platform, not the seller', function (): void {
        $order = ledgerOrder(100_000, direct: true);
        $this->postings->recordPayment($order);

        $entry = $this->postings->recordPayout(
            $order->seller,
            Money::ofNgwee(50_000),
            'payout:seller:'.$order->seller_id.':1',
            fee: Money::ofNgwee(750),
        );

        expect(lineOn($entry, LedgerAccountCode::SellerPayable))->toBe(50_000)
            ->and(lineOn($entry, LedgerAccountCode::LencoFeesExpense))->toBe(750)
            /* Cash leaves for both; the seller is debited only their own. */
            ->and(lineOn($entry, LedgerAccountCode::PlatformCash))->toBe(50_750)
            ->and($this->balances->sellerPayable($order->seller))->toBeMoney(31_300)
            ->and($this->balances->forAccount(LedgerAccountCode::LencoFeesExpense))->toBeMoney(750);
    });

    it('moves a released reserve into the payable without touching cash', function (): void {
        $order = ledgerOrder(100_000, direct: true);
        $this->postings->recordPayment($order);
        $cashBefore = $this->balances->forAccount(LedgerAccountCode::PlatformCash);

        $entry = $this->postings->releaseReserve(
            $order->seller,
            Money::ofNgwee(4_000),
            'reserve-release:seller:'.$order->seller_id.':today',
        );

        expect($entry->recipe)->toBe(PostingRecipe::ReserveRelease)
            ->and($this->balances->sellerReserve($order->seller))->toBeMoney(6_000)
            ->and($this->balances->sellerPayable($order->seller))->toBeMoney(85_300)
            /* Nothing left the platform: it was always the seller's money. */
            ->and($this->balances->forAccount(LedgerAccountCode::PlatformCash))->toBeMoney($cashBefore->ngwee);
    });
});

it('leaves the cached balances consistent after every recipe', function (): void {
    $escrow = ledgerOrder(100_000, 5_000);
    $direct = ledgerOrder(80_000, direct: true);

    $this->postings->recordPayment($escrow);
    $this->postings->recordPayment($direct);
    $this->postings->refund($escrow, Money::ofNgwee(20_000), 'dispute:a');
    $this->postings->releaseEscrow($escrow);
    $this->postings->refund($direct, Money::ofNgwee(15_000), 'dispute:b');
    $this->postings->recordPayout($direct->seller, Money::ofNgwee(1_000), 'payout:b', fee: Money::ofNgwee(50));
    $this->postings->releaseReserve($direct->seller, Money::ofNgwee(500), 'reserve:b');

    expect($this->balances->discrepancies())->toBeEmpty();
});
