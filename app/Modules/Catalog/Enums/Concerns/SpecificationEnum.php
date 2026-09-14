<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums\Concerns;

/**
 * The shared behaviour of the vehicle-specification lists.
 *
 * Fuel type, transmission, drive type and body type are all the same shape: a
 * closed list of values with a human label, offered to a seller as a dropdown
 * and to a buyer as a filter. Each declares its own label() and inherits the
 * rest from here, so adding a case is one line rather than three.
 */
trait SpecificationEnum
{
    /**
     * The dropdown a form renders.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /**
     * Every value, for `Rule::in()` and for the API's documented enums.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The label for a stored value, for pages that render a spec table from
     * raw columns rather than from a cast model.
     */
    public static function labelFor(?string $value): ?string
    {
        return $value === null ? null : self::tryFrom($value)?->label();
    }
}
