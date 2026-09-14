<?php

declare(strict_types=1);

namespace App\Modules\Search\Enums;

/**
 * The orders a buyer may put results in.
 *
 * Recommended is the default and is the only one that does not hand
 * Meilisearch a sort at all: the index's ranking rules already end in
 * quality score descending, then price descending, so relevance tiers stay
 * intact underneath. Naming a sort here overrides that within each relevance
 * group.
 *
 * Distance is deliberately not the default and never leaks into it. A buyer
 * who has not told us where they are cannot be sorted by distance, and one
 * who has still gets Recommended unless they ask for Nearest — sorting by
 * distance by default would quietly hand the whole of Lusaka's search traffic
 * to whichever shops happen to sit in the middle of town.
 */
enum SearchSort: string
{
    case Recommended = 'recommended';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case Nearest = 'nearest';
    case Newest = 'newest';

    public function label(): string
    {
        return match ($this) {
            self::Recommended => 'Recommended',
            self::PriceAsc => 'Price: low to high',
            self::PriceDesc => 'Price: high to low',
            self::Nearest => 'Nearest first',
            self::Newest => 'Newest first',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Recommended => 'Best match first, then the sellers buyers rate highest.',
            self::PriceAsc => 'Cheapest first.',
            self::PriceDesc => 'Most expensive first.',
            self::Nearest => 'Closest to you first. Needs your location.',
            self::Newest => 'Most recently listed first.',
        };
    }

    /**
     * Whether the buyer has to have shared or typed a location for this sort
     * to mean anything.
     */
    public function needsLocation(): bool
    {
        return $this === self::Nearest;
    }

    /**
     * The Meilisearch `sort` parameter, or an empty array for Recommended —
     * which leans on the index's ranking rules instead.
     *
     * @return array<int, string>
     */
    public function meilisearchSort(?float $latitude = null, ?float $longitude = null): array
    {
        return match ($this) {
            self::Recommended => [],
            self::PriceAsc => ['price_ngwee:asc'],
            self::PriceDesc => ['price_ngwee:desc'],
            self::Newest => ['published_at:desc'],
            self::Nearest => $latitude === null || $longitude === null
                ? []
                : [sprintf('_geoPoint(%s, %s):asc', $latitude, $longitude)],
        };
    }

    /**
     * The sort to actually apply, given what the buyer has told us.
     *
     * Asking for Nearest without a location falls back to Recommended rather
     * than erroring: the buyer asked a reasonable question and the honest
     * answer is the default order plus a prompt for their location.
     */
    public function resolvedFor(?float $latitude, ?float $longitude): self
    {
        return $this->needsLocation() && ($latitude === null || $longitude === null)
            ? self::Recommended
            : $this;
    }

    public static function default(): self
    {
        return self::Recommended;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $sort): string => $sort->value, self::cases());
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, needs_location: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $sort): array => [
            'value' => $sort->value,
            'label' => $sort->label(),
            'description' => $sort->description(),
            'needs_location' => $sort->needsLocation(),
        ], self::cases());
    }
}
