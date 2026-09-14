<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

use App\Modules\Sellers\Enums\SellerType;

/**
 * The condition badge on every product card and listing page.
 *
 * This is one of the two badges a listing always carries. It says what the
 * part *is*; the Inspected/Uninspected badge says whether MonaFind has looked
 * at it. The two are independent and are never collapsed into one label —
 * see InspectionStatus.
 */
enum Condition: string
{
    case BrandNew = 'brand_new';
    case Used = 'used';

    /** Pulled from a scrapped vehicle. Forced for car-breaker sellers. */
    case CarBreaker = 'car_breaker';

    public function label(): string
    {
        return match ($this) {
            self::BrandNew => 'Brand New',
            self::Used => 'Used',
            self::CarBreaker => 'Car Breaker',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BrandNew => 'Unused and unfitted, in its original packaging.',
            self::Used => 'Previously fitted and in working order.',
            self::CarBreaker => 'Removed from a scrapped vehicle by a car breaker.',
        };
    }

    /**
     * The badge treatment on the storefront. The card component reads this
     * rather than deciding for itself, so the badge looks the same wherever
     * it is rendered.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::BrandNew => 'default',
            self::Used => 'secondary',
            self::CarBreaker => 'outline',
        };
    }

    /**
     * The only condition a given seller may list under, or null when they may
     * choose.
     *
     * A car breaker strips scrapped vehicles, so nothing they sell is new and
     * "used" understates where it came from. Rather than trusting the form,
     * the condition is decided from the seller's type.
     */
    public static function forcedFor(SellerType $type): ?self
    {
        return $type->sellsBreakerStock() ? self::CarBreaker : null;
    }

    /**
     * The conditions a seller of this type may pick from.
     *
     * @return array<int, self>
     */
    public static function selectableBy(SellerType $type): array
    {
        $forced = self::forcedFor($type);

        return $forced === null
            ? [self::BrandNew, self::Used]
            : [$forced];
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function optionsFor(SellerType $type): array
    {
        return array_map(static fn (self $condition): array => [
            'value' => $condition->value,
            'label' => $condition->label(),
            'description' => $condition->description(),
        ], self::selectableBy($type));
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $condition): array => [
            'value' => $condition->value,
            'label' => $condition->label(),
            'description' => $condition->description(),
        ], self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $condition): string => $condition->value, self::cases());
    }
}
