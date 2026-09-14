<?php

declare(strict_types=1);

use App\Modules\Ledger\Services\PolicyCalculator;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Support\Money\Money;

/**
 * The arithmetic, in isolation from the database.
 *
 * The property test at the bottom is the one that matters: for any gross
 * amount, the four fees plus the seller's net plus the reserve add up to what
 * the buyer paid, exactly. A ngwee lost here is a journal entry that does not
 * balance.
 */
function snapshot(array $overrides = []): MonetisationSnapshot
{
    return MonetisationSnapshot::fromArray([
        'commission_type' => 'percentage',
        'commission_percent' => '7.50',
        'commission_flat_ngwee' => 0,
        'addon_fee_ngwee' => 0,
        'referral_fee_percent' => '0.00',
        'vat_on_commission_percent' => '16.00',
        'reserve_percent' => '10.00',
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->calculator = new PolicyCalculator;
});

it('charges commission on the goods and never on delivery', function (): void {
    $breakdown = $this->calculator->calculate(
        snapshot(),
        goods: Money::ofNgwee(100_000),
        delivery: Money::ofNgwee(5_000),
    );

    /* 7.5% of 100_000, not of 105_000. */
    expect($breakdown->commission)->toBeMoney(7_500)
        ->and($breakdown->vatOnCommission)->toBeMoney(1_200)
        ->and($breakdown->gross)->toBeMoney(105_000)
        /* The courier's fee reaches the seller whole. */
        ->and($breakdown->sellerNet)->toBeMoney(96_300);
});

it('rounds half up, so an invoice can be re-derived by hand', function (): void {
    /* 7.5% of 10_050 is 753.75 ngwee — half-up gives 754. */
    $breakdown = $this->calculator->calculate(
        snapshot(),
        goods: Money::ofNgwee(10_050),
        delivery: Money::zero(),
    );

    expect($breakdown->commission)->toBeMoney(754);

    /* And exactly on the half: 10% of 1_005 is 100.5 → 101, away from zero. */
    $half = $this->calculator->calculate(
        snapshot(['commission_percent' => '10.00', 'vat_on_commission_percent' => '0.00']),
        goods: Money::ofNgwee(1_005),
        delivery: Money::zero(),
    );

    expect($half->commission)->toBeMoney(101);
});

it('charges a flat commission whatever the order was worth', function (): void {
    $flat = snapshot(['commission_type' => 'flat', 'commission_flat_ngwee' => 5_000]);

    $small = $this->calculator->calculate($flat, Money::ofNgwee(20_000), Money::zero());
    $large = $this->calculator->calculate($flat, Money::ofNgwee(900_000), Money::zero());

    expect($small->commission)->toBeMoney(5_000)
        ->and($large->commission)->toBeMoney(5_000);
});

it('charges the add-on fee once and the referral fee only when referred', function (): void {
    $terms = snapshot(['addon_fee_ngwee' => 1_500, 'referral_fee_percent' => '2.00']);

    $direct = $this->calculator->calculate($terms, Money::ofNgwee(100_000), Money::zero());
    $referred = $this->calculator->calculate($terms, Money::ofNgwee(100_000), Money::zero(), referred: true);

    expect($direct->addonFee)->toBeMoney(1_500)
        ->and($direct->referralFee)->toBeMoney(0)
        ->and($referred->addonFee)->toBeMoney(1_500)
        ->and($referred->referralFee)->toBeMoney(2_000);
});

it('withholds the reserve only when asked, and only out of the net', function (): void {
    $held = $this->calculator->calculate(
        snapshot(),
        goods: Money::ofNgwee(100_000),
        delivery: Money::zero(),
        withholdReserve: true,
    );

    expect($held->reserve)->toBeMoney(10_000)
        ->and($held->sellerNet)->toBeMoney(91_300)
        ->and($held->payableToSeller)->toBeMoney(81_300);

    $escrow = $this->calculator->calculate(snapshot(), Money::ofNgwee(100_000), Money::zero());

    expect($escrow->reserve)->toBeMoney(0)
        ->and($escrow->payableToSeller)->toBeMoney($escrow->sellerNet->ngwee);
});

it('never charges more than the order was worth', function (): void {
    /* A K50 flat commission on a K10 order. */
    $breakdown = $this->calculator->calculate(
        snapshot(['commission_type' => 'flat', 'commission_flat_ngwee' => 5_000]),
        goods: Money::ofNgwee(1_000),
        delivery: Money::zero(),
    );

    expect($breakdown->sellerNet)->toBeMoney(0)
        ->and($breakdown->platformTake())->toBeMoney(1_000)
        ->and($breakdown->balances())->toBeTrue();
});

it('excludes VAT from revenue, because it was never the platform\'s', function (): void {
    $breakdown = $this->calculator->calculate(
        snapshot(['addon_fee_ngwee' => 1_000]),
        goods: Money::ofNgwee(100_000),
        delivery: Money::zero(),
    );

    expect($breakdown->revenue())->toBeMoney(8_500)
        ->and($breakdown->platformTake())->toBeMoney(9_700)
        /* The invoice is commission plus its VAT and nothing else. */
        ->and($breakdown->invoiceTotal())->toBeMoney(8_700);
});

/**
 * The property that the ledger depends on.
 */
it('loses no ngwee, for any gross amount or set of terms', function (): void {
    $calculator = new PolicyCalculator;

    mt_srand(20260911);

    for ($i = 0; $i < 2_000; $i++) {
        $terms = snapshot([
            'commission_type' => mt_rand(0, 4) === 0 ? 'flat' : 'percentage',
            'commission_percent' => sprintf('%d.%02d', mt_rand(0, 25), mt_rand(0, 99)),
            'commission_flat_ngwee' => mt_rand(0, 20_000),
            'addon_fee_ngwee' => mt_rand(0, 3) === 0 ? mt_rand(1, 5_000) : 0,
            'referral_fee_percent' => sprintf('%d.%02d', mt_rand(0, 5), mt_rand(0, 99)),
            'vat_on_commission_percent' => sprintf('%d.%02d', mt_rand(0, 20), mt_rand(0, 99)),
            'reserve_percent' => sprintf('%d.%02d', mt_rand(0, 30), mt_rand(0, 99)),
        ]);

        $goods = Money::ofNgwee(mt_rand(1, 10_000_000));
        $delivery = Money::ofNgwee(mt_rand(0, 50_000));
        $referred = mt_rand(0, 1) === 1;
        $reserve = mt_rand(0, 1) === 1;

        $breakdown = $calculator->calculate($terms, $goods, $delivery, $referred, $reserve);

        $context = sprintf(
            'goods %d, delivery %d, commission %s%%, flat %d, addon %d, referral %s%%, vat %s%%, reserve %s%%',
            $goods->ngwee,
            $delivery->ngwee,
            $terms->commissionPercent,
            $terms->commissionFlat->ngwee,
            $terms->addonFee->ngwee,
            $terms->referralFeePercent,
            $terms->vatOnCommissionPercent,
            $terms->reservePercent,
        );

        /* Everything the buyer paid is accounted for, to the ngwee. */
        expect(
            $breakdown->payableToSeller->ngwee
            + $breakdown->reserve->ngwee
            + $breakdown->commission->ngwee
            + $breakdown->addonFee->ngwee
            + $breakdown->referralFee->ngwee
            + $breakdown->vatOnCommission->ngwee,
        )->toBe($breakdown->gross->ngwee, $context);

        /* And nothing anywhere went negative. */
        expect($breakdown->sellerNet->isNegative())->toBeFalse($context)
            ->and($breakdown->payableToSeller->isNegative())->toBeFalse($context)
            ->and($breakdown->reserve->isNegative())->toBeFalse($context)
            ->and($breakdown->balances())->toBeTrue($context);
    }
});
