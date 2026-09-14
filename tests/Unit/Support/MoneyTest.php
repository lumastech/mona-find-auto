<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Money\MoneyException;
use App\Support\Money\RoundingMode;

it('reads a kwacha decimal string as whole ngwee', function (string $input, int $expected) {
    expect(Money::ofKwacha($input)->ngwee)->toBe($expected);
})->with([
    ['10.75', 1075],
    ['10.7', 1070],
    ['10', 1000],
    ['0.05', 5],
    ['0.00', 0],
    ['-0.05', -5],
    ['1299.05', 129905],
    ['K 1,299.05', 129905],
    ['ZMW 1299.05', 129905],
    ['-1,000.50', -100050],
]);

it('reads a whole number of kwacha', function () {
    expect(Money::ofKwacha(10)->ngwee)->toBe(1000);
});

it('treats integers as ngwee and strings as kwacha', function () {
    expect(Money::from(1075)->ngwee)->toBe(1075)
        ->and(Money::from('10.75')->ngwee)->toBe(1075);
});

it('rejects an amount it cannot read exactly', function (string $input) {
    expect(fn () => Money::ofKwacha($input))->toThrow(MoneyException::class);
})->with([
    ['10.751'],
    ['ten'],
    [''],
    ['1.2.3'],
    ['10,75.00.1'],
]);

it('ignores trailing zeros beyond the minor unit', function () {
    expect(Money::ofKwacha('10.7500')->ngwee)->toBe(1075);
});

it('adds and subtracts without drift', function () {
    $total = Money::ofKwacha('0.10')
        ->plus(Money::ofKwacha('0.20'))
        ->minus(Money::ofKwacha('0.30'));

    expect($total->ngwee)->toBe(0)
        ->and($total->isZero())->toBeTrue();
});

it('adds a tenth a hundred times and lands exactly on ten kwacha', function () {
    $total = Money::zero();

    for ($i = 0; $i < 100; $i++) {
        $total = $total->plus(Money::ofKwacha('0.10'));
    }

    expect($total->ngwee)->toBe(1000)
        ->and($total->format())->toBe('K 10.00');
});

it('multiplies by a whole number', function () {
    expect(Money::ofKwacha('19.99')->times(3)->ngwee)->toBe(5997);
});

it('takes a percentage with explicit rounding', function () {
    /** 7.5% of K 133.33 is 999.975 ngwee. */
    $gross = Money::ofKwacha('133.33');

    expect($gross->percentage('7.5')->ngwee)->toBe(1000)
        ->and($gross->percentage('7.5', RoundingMode::Floor)->ngwee)->toBe(999)
        ->and($gross->percentage('7.5', RoundingMode::Ceiling)->ngwee)->toBe(1000);
});

it('refuses an inexact percentage when exactness is demanded', function () {
    expect(fn () => Money::ofKwacha('133.33')->percentage('7.5', RoundingMode::Exact))
        ->toThrow(MoneyException::class);
});

it('rounds halves away from zero by default', function () {
    /** Half of 5 ngwee is 2.5. */
    expect(Money::ofNgwee(5)->multiplyByRatio(1, 2)->ngwee)->toBe(3)
        ->and(Money::ofNgwee(-5)->multiplyByRatio(1, 2)->ngwee)->toBe(-3);
});

it('rounds halves to even when asked', function () {
    expect(Money::ofNgwee(5)->multiplyByRatio(1, 2, RoundingMode::HalfEven)->ngwee)->toBe(2)
        ->and(Money::ofNgwee(15)->multiplyByRatio(1, 2, RoundingMode::HalfEven)->ngwee)->toBe(8);
});

it('refuses to divide by zero', function () {
    expect(fn () => Money::ofNgwee(100)->multiplyByRatio(1, 0))->toThrow(MoneyException::class);
});

it('allocates an amount without losing a ngwee', function () {
    $shares = Money::ofNgwee(100)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $share): int => $share->ngwee, $shares))->toBe([34, 33, 33])
        ->and(Money::sum(...$shares)->ngwee)->toBe(100);
});

it('allocates a negative amount without losing a ngwee', function () {
    $shares = Money::ofNgwee(-100)->allocate([1, 1, 1]);

    expect(Money::sum(...$shares)->ngwee)->toBe(-100);
});

it('allocates by weight', function () {
    $shares = Money::ofKwacha('100.00')->allocate([70, 30]);

    expect($shares[0]->ngwee)->toBe(7000)
        ->and($shares[1]->ngwee)->toBe(3000);
});

it('rejects an allocation with no weight', function () {
    expect(fn () => Money::ofNgwee(100)->allocate([0, 0]))->toThrow(MoneyException::class);
});

it('compares amounts', function () {
    $ten = Money::ofKwacha('10.00');
    $twenty = Money::ofKwacha('20.00');

    expect($ten->lessThan($twenty))->toBeTrue()
        ->and($twenty->greaterThan($ten))->toBeTrue()
        ->and($ten->equals(Money::ofNgwee(1000)))->toBeTrue()
        ->and($ten->lessThanOrEqualTo($ten))->toBeTrue()
        ->and($ten->greaterThanOrEqualTo($ten))->toBeTrue()
        ->and(Money::min($twenty, $ten)->ngwee)->toBe(1000)
        ->and(Money::max($twenty, $ten)->ngwee)->toBe(2000);
});

it('sums an empty list to zero', function () {
    expect(Money::sum()->ngwee)->toBe(0);
});

it('formats for display', function () {
    expect(Money::ofNgwee(129905)->format())->toBe('K 1,299.05')
        ->and(Money::ofNgwee(129905)->format(withSymbol: false))->toBe('1,299.05')
        ->and(Money::ofNgwee(-5)->format())->toBe('-K 0.05')
        ->and(Money::ofNgwee(0)->format())->toBe('K 0.00')
        ->and((string) Money::ofNgwee(1075))->toBe('K 10.75');
});

it('renders a plain decimal string', function () {
    expect(Money::ofNgwee(129905)->toDecimalString())->toBe('1299.05')
        ->and(Money::ofNgwee(-5)->toDecimalString())->toBe('-0.05')
        ->and(Money::ofNgwee(1000)->toDecimalString())->toBe('10.00');
});

it('serialises with the amount, currency and both string forms', function () {
    expect(Money::ofNgwee(1075)->toArray())->toBe([
        'ngwee' => 1075,
        'currency' => 'ZMW',
        'formatted' => 'K 10.75',
        'decimal' => '10.75',
    ]);
});

it('negates and absolutes', function () {
    expect(Money::ofNgwee(1075)->negated()->ngwee)->toBe(-1075)
        ->and(Money::ofNgwee(-1075)->absolute()->ngwee)->toBe(1075)
        ->and(Money::ofNgwee(-1)->isNegative())->toBeTrue()
        ->and(Money::ofNgwee(1)->isPositive())->toBeTrue();
});
