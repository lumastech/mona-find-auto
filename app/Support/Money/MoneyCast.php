<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts an integer ngwee column to and from a Money object.
 *
 * Declare it on the model as `'price' => MoneyCast::class` and assign either
 * a Money, an integer number of ngwee, or a decimal string such as "10.75".
 * Anything else — a float above all — is rejected rather than rounded, which
 * is why the set type is left open and checked at runtime.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            throw MoneyException::floatRejected();
        }

        if (! is_int($value) && ! is_string($value)) {
            throw MoneyException::unparsable(get_debug_type($value));
        }

        return Money::ofNgwee((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (is_float($value)) {
            throw MoneyException::floatRejected();
        }

        if (! $value instanceof Money && ! is_int($value) && ! is_string($value)) {
            throw MoneyException::unparsable(get_debug_type($value));
        }

        return [$key => Money::from($value)->ngwee];
    }
}
