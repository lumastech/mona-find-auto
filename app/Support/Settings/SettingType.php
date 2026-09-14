<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Support\Money\Money;
use JsonException;

/**
 * The declared type of a stored setting. Values live in the database as
 * strings; the type decides how they are read back and written down.
 */
enum SettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Decimal = 'decimal';
    case Money = 'money';
    case Array = 'array';

    /**
     * Turn the stored string into a usable PHP value.
     */
    public function decode(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Decimal => $value,
            self::Integer => (int) $value,
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            self::Money => Money::ofNgwee((int) $value),
            self::Array => $this->decodeArray($value),
        };
    }

    /**
     * Turn a PHP value into the string that goes into the database.
     */
    public function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Decimal => (string) $this->stringable($value),
            self::Integer => (string) (int) $this->stringable($value),
            self::Boolean => $value ? '1' : '0',
            self::Money => (string) ($value instanceof Money ? $value->ngwee : Money::from($this->moneyInput($value))->ngwee),
            self::Array => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeArray(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function stringable(mixed $value): string|int|float
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        throw new InvalidSettingValue($this, $value);
    }

    private function moneyInput(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidSettingValue($this, $value);
    }
}
