<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * Whether MonaFind staff have physically inspected the part.
 *
 * The second of the two badges every listing carries, and deliberately
 * separate from the condition: a brand-new part can be uninspected and a
 * car-breaker part can be inspected. Collapsing them would let a buyer read
 * "Brand New" as "checked by MonaFind", which is exactly the confusion the
 * pair of badges exists to prevent.
 *
 * Uninspected is the default and only staff may change it.
 */
enum InspectionStatus: string
{
    case Uninspected = 'uninspected';
    case Inspected = 'inspected';

    public function label(): string
    {
        return match ($this) {
            self::Uninspected => 'Uninspected',
            self::Inspected => 'Inspected',
        };
    }

    /**
     * What the badge means, shown on hover and to screen readers — a buyer
     * should never have to guess who did the inspecting.
     */
    public function description(): string
    {
        return match ($this) {
            self::Uninspected => 'MonaFind has not inspected this item.',
            self::Inspected => 'MonaFind staff have inspected this item.',
        };
    }

    public function badgeVariant(): string
    {
        return $this === self::Inspected ? 'default' : 'outline';
    }

    public function isInspected(): bool
    {
        return $this === self::Inspected;
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
            'description' => $status->description(),
        ], self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
