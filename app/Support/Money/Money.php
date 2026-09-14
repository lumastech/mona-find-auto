<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

/**
 * An immutable amount of Zambian Kwacha held as an integer number of ngwee.
 *
 * Every monetary value in MonaFindAuto — prices, commission, VAT, ledger
 * lines, payouts — is represented by this object. No operation on it ever
 * produces or consumes a float, so amounts survive arithmetic exactly.
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class Money implements Arrayable, JsonSerializable, Stringable
{
    /**
     * The platform trades in one currency, so these are constants rather than
     * configuration: a value object must never need the container to add two
     * amounts together.
     */
    public const CURRENCY_CODE = 'ZMW';

    public const SYMBOL = 'K';

    public const MINOR_UNIT_NAME = 'ngwee';

    public const MINOR_UNITS_PER_MAJOR = 100;

    public const DECIMALS = 2;

    private function __construct(
        /** The amount in ngwee. 1 ZMW = 100 ngwee. */
        public int $ngwee,
    ) {}

    /**
     * Build from a whole number of ngwee (the storage representation).
     */
    public static function ofNgwee(int $ngwee): self
    {
        return new self($ngwee);
    }

    /**
     * Build from a kwacha amount expressed as an integer or a decimal string.
     *
     * Floats are rejected outright: "10.75" is exact, 10.75 is not.
     */
    public static function ofKwacha(int|string $kwacha): self
    {
        if (is_int($kwacha)) {
            return new self($kwacha * self::MINOR_UNITS_PER_MAJOR);
        }

        return self::parse($kwacha);
    }

    /**
     * Read a human-entered amount such as "10.75", "K 1,299", "-0.05" or "10".
     */
    public static function parse(string $amount): self
    {
        $normalised = str_replace([' ', ',', 'K', 'k', 'ZMW', 'zmw', "\u{00A0}"], '', trim($amount));

        if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $normalised, $matches)) {
            throw MoneyException::unparsable($amount);
        }

        $decimals = self::DECIMALS;
        $fraction = $matches['fraction'] ?? '';

        if (strlen($fraction) > $decimals) {
            /** Trailing zeros beyond the minor unit are harmless; real precision is not. */
            if (rtrim(substr($fraction, $decimals), '0') !== '') {
                throw MoneyException::unparsable($amount);
            }

            $fraction = substr($fraction, 0, $decimals);
        }

        $ngwee = (int) $matches['whole'] * self::MINOR_UNITS_PER_MAJOR
            + (int) str_pad($fraction, $decimals, '0');

        return new self($matches['sign'] === '-' ? -$ngwee : $ngwee);
    }

    /**
     * Read any of the representations this application accepts.
     *
     * Mind the units: an integer is ngwee and a string is kwacha, so
     * `from(10)` is K 0.10 while `from('10')` is K 10.00. This is the same
     * rule MoneyCast applies to model attributes.
     */
    public static function from(self|int|string $amount): self
    {
        return match (true) {
            $amount instanceof self => $amount,
            is_int($amount) => new self($amount),
            default => self::parse($amount),
        };
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $addend): self
    {
        return new self($this->ngwee + $addend->ngwee);
    }

    public function minus(self $subtrahend): self
    {
        return new self($this->ngwee - $subtrahend->ngwee);
    }

    /**
     * Scale by a whole number — quantity lines, for instance.
     */
    public function times(int $multiplier): self
    {
        return new self($this->ngwee * $multiplier);
    }

    /**
     * Scale by the exact rational $numerator/$denominator.
     *
     * This is the only way to shrink an amount, so that the rounding decision
     * is always explicit at the call site.
     */
    public function multiplyByRatio(int $numerator, int $denominator, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        if ($denominator === 0) {
            throw MoneyException::divisionByZero();
        }

        return new self(self::divideRounded($this->ngwee * $numerator, $denominator, $rounding));
    }

    /**
     * Take a percentage of this amount, e.g. `$order->percentage('8.5')` for
     * an 8.5% commission. The percentage itself is parsed exactly.
     */
    public function percentage(int|string $percent, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        return $this->multiplyByRatio(self::percentToBasisPoints($percent), 10_000, $rounding);
    }

    /**
     * Split this amount across the given integer ratios without losing or
     * inventing a single ngwee; the remainder goes to the earliest buckets.
     *
     * @param  array<int, int>  $ratios
     * @return array<int, self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);

        if ($total <= 0 || array_filter($ratios, static fn (int $ratio): bool => $ratio < 0) !== []) {
            throw MoneyException::invalidAllocation($ratios);
        }

        $shares = [];
        $remainder = $this->ngwee;

        foreach ($ratios as $ratio) {
            $share = intdiv($this->ngwee * $ratio, $total);
            $shares[] = $share;
            $remainder -= $share;
        }

        for ($index = 0; $remainder > 0; $index++, $remainder--) {
            $shares[$index % count($shares)] += 1;
        }

        for ($index = 0; $remainder < 0; $index++, $remainder++) {
            $shares[$index % count($shares)] -= 1;
        }

        return array_map(static fn (int $share): self => new self($share), $shares);
    }

    public function negated(): self
    {
        return new self(-$this->ngwee);
    }

    public function absolute(): self
    {
        return new self(abs($this->ngwee));
    }

    public function isZero(): bool
    {
        return $this->ngwee === 0;
    }

    public function isPositive(): bool
    {
        return $this->ngwee > 0;
    }

    public function isNegative(): bool
    {
        return $this->ngwee < 0;
    }

    public function equals(self $other): bool
    {
        return $this->ngwee === $other->ngwee;
    }

    public function greaterThan(self $other): bool
    {
        return $this->ngwee > $other->ngwee;
    }

    public function greaterThanOrEqualTo(self $other): bool
    {
        return $this->ngwee >= $other->ngwee;
    }

    public function lessThan(self $other): bool
    {
        return $this->ngwee < $other->ngwee;
    }

    public function lessThanOrEqualTo(self $other): bool
    {
        return $this->ngwee <= $other->ngwee;
    }

    /**
     * Sum any number of amounts; an empty list is zero.
     */
    public static function sum(self ...$amounts): self
    {
        return array_reduce(
            $amounts,
            static fn (self $carry, self $amount): self => $carry->plus($amount),
            self::zero(),
        );
    }

    public static function min(self $first, self ...$rest): self
    {
        return array_reduce(
            $rest,
            static fn (self $carry, self $amount): self => $amount->lessThan($carry) ? $amount : $carry,
            $first,
        );
    }

    public static function max(self $first, self ...$rest): self
    {
        return array_reduce(
            $rest,
            static fn (self $carry, self $amount): self => $amount->greaterThan($carry) ? $amount : $carry,
            $first,
        );
    }

    /**
     * The amount as a plain decimal string, e.g. "-1299.05". Suitable for
     * CSV exports and API payloads; never for further arithmetic.
     */
    public function toDecimalString(): string
    {
        $sign = $this->ngwee < 0 ? '-' : '';
        $absolute = abs($this->ngwee);
        $perMajor = self::MINOR_UNITS_PER_MAJOR;

        return sprintf(
            '%s%d.%0'.self::DECIMALS.'d',
            $sign,
            intdiv($absolute, $perMajor),
            $absolute % $perMajor,
        );
    }

    /**
     * A display string such as "K 1,299.05".
     */
    public function format(bool $withSymbol = true): string
    {
        $sign = $this->ngwee < 0 ? '-' : '';
        $absolute = abs($this->ngwee);
        $perMajor = self::MINOR_UNITS_PER_MAJOR;

        $formatted = number_format(intdiv($absolute, $perMajor)).'.'
            .str_pad((string) ($absolute % $perMajor), self::DECIMALS, '0', STR_PAD_LEFT);

        return $withSymbol
            ? $sign.self::SYMBOL.' '.$formatted
            : $sign.$formatted;
    }

    /**
     * @return array{ngwee: int, currency: string, formatted: string, decimal: string}
     */
    public function toArray(): array
    {
        return [
            'ngwee' => $this->ngwee,
            'currency' => self::CURRENCY_CODE,
            'formatted' => $this->format(),
            'decimal' => $this->toDecimalString(),
        ];
    }

    /**
     * @return array{ngwee: int, currency: string, formatted: string, decimal: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * Convert "8.5" or 8 into hundredths of a percent (basis points).
     */
    private static function percentToBasisPoints(int|string $percent): int
    {
        if (is_int($percent)) {
            return $percent * 100;
        }

        if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', trim($percent), $matches)) {
            throw MoneyException::unparsable($percent);
        }

        $fraction = $matches['fraction'] ?? '';

        if (strlen($fraction) > 2 && rtrim(substr($fraction, 2), '0') !== '') {
            throw MoneyException::unparsable($percent);
        }

        $basisPoints = (int) $matches['whole'] * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $matches['sign'] === '-' ? -$basisPoints : $basisPoints;
    }

    /**
     * Integer division that applies the requested rounding to the remainder.
     */
    private static function divideRounded(int $dividend, int $divisor, RoundingMode $rounding): int
    {
        $quotient = intdiv($dividend, $divisor);
        $remainder = $dividend - $quotient * $divisor;

        if ($remainder === 0) {
            return $quotient;
        }

        $negative = ($dividend < 0) !== ($divisor < 0);
        $twiceRemainder = abs($remainder) * 2;
        $absoluteDivisor = abs($divisor);

        $roundAway = match ($rounding) {
            RoundingMode::Exact => throw MoneyException::inexactDivision($dividend, 1, $divisor),
            RoundingMode::HalfUp => $twiceRemainder >= $absoluteDivisor,
            RoundingMode::HalfDown => $twiceRemainder > $absoluteDivisor,
            RoundingMode::HalfEven => $twiceRemainder > $absoluteDivisor
                || ($twiceRemainder === $absoluteDivisor && $quotient % 2 !== 0),
            RoundingMode::Floor => $negative,
            RoundingMode::Ceiling => ! $negative,
        };

        if (! $roundAway) {
            return $quotient;
        }

        return $negative ? $quotient - 1 : $quotient + 1;
    }

    /**
     * The currency, in the shape shared with the browser and the API.
     *
     * @return array{code: string, symbol: string, minor_unit_name: string, minor_units_per_major: int, decimals: int}
     */
    public static function currencyDescriptor(): array
    {
        return [
            'code' => self::CURRENCY_CODE,
            'symbol' => self::SYMBOL,
            'minor_unit_name' => self::MINOR_UNIT_NAME,
            'minor_units_per_major' => self::MINOR_UNITS_PER_MAJOR,
            'decimals' => self::DECIMALS,
        ];
    }
}
