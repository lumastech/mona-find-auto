<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Raised whenever an operation would produce an amount that is not an exact
 * whole number of ngwee, or when an input cannot be read as an amount.
 */
class MoneyException extends InvalidArgumentException
{
    public static function unparsable(string $value): self
    {
        return new self("Unable to read [{$value}] as a ZMW amount. Expected a decimal string such as \"10.75\".");
    }

    public static function inexactDivision(int $amount, int $numerator, int $denominator): self
    {
        return new self("Multiplying {$amount} ngwee by {$numerator}/{$denominator} does not yield a whole number of ngwee and RoundingMode::Exact was requested.");
    }

    public static function floatRejected(): self
    {
        return new self('Floats are never accepted in a money path. Pass an integer number of ngwee or a decimal string such as "10.75".');
    }

    public static function divisionByZero(): self
    {
        return new self('Cannot divide a money amount by zero.');
    }

    /**
     * @param  array<int, int>  $ratios
     */
    public static function invalidAllocation(array $ratios): self
    {
        return new self('Cannot allocate an amount across ratios ['.implode(', ', $ratios).']: ratios must be non-negative and sum to more than zero.');
    }
}
