<?php

declare(strict_types=1);

namespace App\Modules\Search\Enums;

/**
 * How well a result answers what the buyer actually typed.
 *
 * Search on a parts marketplace fails in a particular way: somebody types
 * "toyota hilux 2010 brake pads" and there is nothing that matches all five
 * things at once. Showing them an empty page is wrong — the part they need
 * probably exists under a slightly different name — but quietly showing
 * near-misses as though they were exact is worse, because a buyer will drive
 * across Lusaka for the wrong disc.
 *
 * So every result carries the tier it earned, and the storefront says so.
 * The ordering below is the ordering on the page: exact first, partial after,
 * and the category fallback only when the first two found nothing at all.
 */
enum MatchTier: string
{
    /** Every word the buyer typed is in this listing. */
    case Exact = 'exact';

    /** Some of the words matched. Meilisearch's own relevance decides how well. */
    case Partial = 'partial';

    /** Nothing matched, so this is the closest category the words point at. */
    case Related = 'related';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Exact match',
            self::Partial => 'Partial match',
            self::Related => 'Related part',
        };
    }

    /**
     * The "Why am I seeing this?" answer, in the buyer's terms.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Exact => 'Every word you searched for appears in this listing.',
            self::Partial => 'Some of your words matched this listing. Check the fitment before you buy.',
            self::Related => 'Nothing matched your exact search, so this is a part from the closest category.',
        };
    }

    /**
     * What the page says above the results, or null when the results speak
     * for themselves.
     */
    public function notice(): ?string
    {
        return match ($this) {
            self::Exact, self::Partial => null,
            self::Related => 'No exact matches — showing related parts',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Exact => 'secondary',
            self::Partial => 'outline',
            self::Related => 'outline',
        };
    }

    /**
     * @return array<int, array{value: string, label: string, explanation: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $tier): array => [
            'value' => $tier->value,
            'label' => $tier->label(),
            'explanation' => $tier->explanation(),
        ], self::cases());
    }
}
