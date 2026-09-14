<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * How long ago a seller last swore their stock was real.
 *
 * A Zambian parts shop sells the same alternator over the counter and on
 * MonaFind, so a listing goes stale by simply sitting there. The states below
 * are the platform's answer: confirm every few days or slide down the
 * rankings, then carry a warning label, then disappear.
 *
 * The day counts are admin-configurable (freshness.* in settings) because the
 * right number is a market question, not a code question. The ordering of the
 * states is not configurable — each one is strictly worse than the last.
 */
enum FreshnessState: string
{
    /** Confirmed within the fresh window. No penalty, no label. */
    case Fresh = 'fresh';

    /** Past the fresh window but not yet doubtful. A small ranking demotion. */
    case Ageing = 'ageing';

    /** Nobody has vouched for this stock in days. Labelled, and heavily demoted. */
    case Unconfirmed = 'unconfirmed';

    /** Off the storefront entirely until the seller confirms it again. */
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Stock confirmed',
            self::Ageing => 'Stock confirmed recently',
            self::Unconfirmed => 'Stock unconfirmed',
            self::Hidden => 'Hidden — stock not confirmed',
        };
    }

    /**
     * What a buyer is told, or null when there is nothing worth saying.
     *
     * Fresh stock gets no label: a badge on every listing that says "normal"
     * is noise, and it would make the warning on the others invisible.
     */
    public function storefrontLabel(): ?string
    {
        return match ($this) {
            self::Fresh => null,
            self::Ageing => null,
            self::Unconfirmed => 'Stock unconfirmed',
            self::Hidden => 'Stock unconfirmed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Fresh => 'The seller confirmed this stock in the last few days.',
            self::Ageing => 'Confirm your stock to keep this listing ranking well.',
            self::Unconfirmed => 'The seller has not confirmed this stock recently. Check with them before you travel.',
            self::Hidden => 'This listing is hidden from buyers until you confirm the stock.',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Fresh => 'secondary',
            self::Ageing => 'outline',
            self::Unconfirmed, self::Hidden => 'destructive',
        };
    }

    /**
     * Whether a buyer may see a listing in this state.
     */
    public function isVisibleToBuyers(): bool
    {
        return $this !== self::Hidden;
    }

    /**
     * Whether the seller needs to do something about it.
     */
    public function needsConfirmation(): bool
    {
        return $this !== self::Fresh;
    }

    /**
     * The multiplier applied to a listing's quality score in search.
     *
     * Ageing is a nudge; Unconfirmed is a demotion a seller will notice. Both
     * are multipliers rather than subtractions so that a strong shop and a
     * weak one are demoted proportionally by the same neglect.
     */
    public function rankingMultiplier(): float
    {
        return match ($this) {
            self::Fresh => 1.0,
            self::Ageing => 0.9,
            self::Unconfirmed => 0.5,
            self::Hidden => 0.0,
        };
    }

    /**
     * The state a listing is in, given how many whole days ago it was last
     * confirmed and the platform's configured windows.
     *
     * A listing that was never confirmed is treated as confirmed when it was
     * published — a seller who has just listed a part has, by listing it,
     * said they have it. The caller decides what to pass; this only maps a
     * number of days onto a state.
     *
     * @param  array{fresh: int, ageing: int, hidden: int}  $thresholds
     */
    public static function forAge(int $days, array $thresholds): self
    {
        return match (true) {
            $days <= $thresholds['fresh'] => self::Fresh,
            $days <= $thresholds['ageing'] => self::Ageing,
            $days <= $thresholds['hidden'] => self::Unconfirmed,
            default => self::Hidden,
        };
    }

    /**
     * The states a buyer's queries are restricted to.
     *
     * @return array<int, string>
     */
    public static function visibleValues(): array
    {
        return array_values(array_map(
            static fn (self $state): string => $state->value,
            array_filter(self::cases(), static fn (self $state): bool => $state->isVisibleToBuyers()),
        ));
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, variant: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $state): array => [
            'value' => $state->value,
            'label' => $state->label(),
            'description' => $state->description(),
            'variant' => $state->badgeVariant(),
        ], self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
