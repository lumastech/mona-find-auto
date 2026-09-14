<?php

declare(strict_types=1);

use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\LedgerService;
use App\Modules\Ledger\Support\Posting;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;

/**
 * The cached balances are a convenience. This is what makes them trustworthy:
 * after a thousand random postings, every cached figure still equals the sum
 * of the lines beneath it, to the ngwee.
 *
 * A materialised balance nobody can check against the lines is a number
 * nobody should trust.
 */
it('agrees with the raw line sums after a thousand postings', function (): void {
    $ledger = app(LedgerService::class);
    $balances = app(LedgerBalances::class);

    $orders = Order::factory()->count(5)->create();
    $sellers = Seller::factory()->count(3)->create();

    /* A fixed seed: a failure has to be reproducible to be worth anything. */
    mt_srand(20260911);

    for ($i = 1; $i <= 1_000; $i++) {
        $amount = Money::ofNgwee(mt_rand(1, 5_000_000));
        $order = $orders[mt_rand(0, $orders->count() - 1)];
        $seller = $sellers[mt_rand(0, $sellers->count() - 1)];

        $posting = match (mt_rand(0, 3)) {
            0 => Posting::make(PostingRecipe::EscrowPayment, 'Payment', 'consistency:'.$i)
                ->debit(LedgerAccountCode::PlatformCash, $amount)
                ->credit(LedgerAccountCode::EscrowHeld, $amount, $order),

            1 => Posting::make(PostingRecipe::EscrowRelease, 'Release', 'consistency:'.$i)
                ->debit(LedgerAccountCode::EscrowHeld, $amount, $order)
                ->credit(LedgerAccountCode::SellerPayable, $amount, $seller),

            2 => Posting::make(PostingRecipe::Payout, 'Payout', 'consistency:'.$i)
                ->debit(LedgerAccountCode::SellerPayable, $amount, $seller)
                ->debit(LedgerAccountCode::LencoFeesExpense, Money::ofNgwee(500))
                ->credit(LedgerAccountCode::PlatformCash, $amount->plus(Money::ofNgwee(500))),

            default => Posting::make(PostingRecipe::ReserveRelease, 'Reserve', 'consistency:'.$i)
                ->debit(LedgerAccountCode::SellerReserve, $amount, $seller)
                ->credit(LedgerAccountCode::SellerPayable, $amount, $seller),
        };

        $ledger->post($posting);
    }

    expect($balances->discrepancies())->toBeEmpty();

    /* And spelled out for the figures anybody actually reads. */
    foreach (LedgerAccountCode::cases() as $code) {
        expect($balances->forAccount($code)->ngwee)
            ->toBe($balances->recompute($code)->ngwee, $code->value.' account total');
    }

    foreach ($orders as $order) {
        expect($balances->escrowHeldFor($order)->ngwee)
            ->toBe($balances->recompute(LedgerAccountCode::EscrowHeld, $order)->ngwee);
    }

    foreach ($sellers as $seller) {
        expect($balances->sellerPayable($seller)->ngwee)
            ->toBe($balances->recompute(LedgerAccountCode::SellerPayable, $seller)->ngwee)
            ->and($balances->sellerReserve($seller)->ngwee)
            ->toBe($balances->recompute(LedgerAccountCode::SellerReserve, $seller)->ngwee);
    }
});

it('balances to zero across every account, always', function (): void {
    $ledger = app(LedgerService::class);
    $order = Order::factory()->create();
    $seller = Seller::factory()->create();

    $ledger->post(
        Posting::make(PostingRecipe::EscrowPayment, 'In', 'zero-sum:1')
            ->debit(LedgerAccountCode::PlatformCash, Money::ofNgwee(100_000))
            ->credit(LedgerAccountCode::EscrowHeld, Money::ofNgwee(100_000), $order),
    );

    $ledger->post(
        Posting::make(PostingRecipe::EscrowRelease, 'Out', 'zero-sum:2')
            ->debit(LedgerAccountCode::EscrowHeld, Money::ofNgwee(100_000), $order)
            ->credit(LedgerAccountCode::SellerPayable, Money::ofNgwee(92_500), $seller)
            ->credit(LedgerAccountCode::CommissionRevenue, Money::ofNgwee(7_500)),
    );

    /*
     * The double-entry identity itself: across the whole ledger, the debits
     * equal the credits. Not per account and not per entry — everywhere.
     */
    $debits = (int) JournalLine::query()->where('direction', 'debit')->sum('amount_ngwee');
    $credits = (int) JournalLine::query()->where('direction', 'credit')->sum('amount_ngwee');

    expect($debits)->toBe($credits);
});

it('rebuilds the cache from the lines', function (): void {
    $ledger = app(LedgerService::class);
    $balances = app(LedgerBalances::class);
    $order = Order::factory()->create();
    $seller = Seller::factory()->create();

    $ledger->post(
        Posting::make(PostingRecipe::EscrowPayment, 'In', 'rebuild:1')
            ->debit(LedgerAccountCode::PlatformCash, Money::ofNgwee(50_000))
            ->credit(LedgerAccountCode::EscrowHeld, Money::ofNgwee(50_000), $order),
    );

    $ledger->post(
        Posting::make(PostingRecipe::EscrowRelease, 'Out', 'rebuild:2')
            ->debit(LedgerAccountCode::EscrowHeld, Money::ofNgwee(20_000), $order)
            ->credit(LedgerAccountCode::SellerPayable, Money::ofNgwee(20_000), $seller),
    );

    $before = [
        'escrow' => $balances->escrowHeldFor($order)->ngwee,
        'payable' => $balances->sellerPayable($seller)->ngwee,
        'cash' => $balances->forAccount(LedgerAccountCode::PlatformCash)->ngwee,
    ];

    $balances->rebuild();

    expect($balances->escrowHeldFor($order)->ngwee)->toBe($before['escrow'])
        ->and($balances->sellerPayable($seller)->ngwee)->toBe($before['payable'])
        ->and($balances->forAccount(LedgerAccountCode::PlatformCash)->ngwee)->toBe($before['cash'])
        ->and($balances->discrepancies())->toBeEmpty();
});
