<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Enums;

/**
 * How a seller's trust score reads to a moderator, and what to do about it.
 *
 * The bands exist so the admin review list is a worklist rather than a
 * leaderboard. A number tells staff who is worst; a band plus recommended
 * actions tells them what the platform expects to be done and in what order,
 * which is the difference between a queue that gets worked and one that gets
 * scrolled.
 *
 * Nothing here acts on its own. Suspending a shop, reverting it to escrow or
 * pulling its listings are all decisions a person takes with a reason
 * attached; this enum only tells them which decision is on the table.
 */
enum TrustBand: string
{
    case Strong = 'strong';
    case Fair = 'fair';
    case Watch = 'watch';
    case Critical = 'critical';

    /**
     * The score at or above which a seller is in this band, out of 100.
     */
    public function floor(): float
    {
        return match ($this) {
            self::Strong => 80.0,
            self::Fair => 60.0,
            self::Watch => 40.0,
            self::Critical => 0.0,
        };
    }

    public static function forScore(float $score): self
    {
        foreach ([self::Strong, self::Fair, self::Watch] as $band) {
            if ($score >= $band->floor()) {
                return $band;
            }
        }

        return self::Critical;
    }

    public function label(): string
    {
        return match ($this) {
            self::Strong => 'Strong',
            self::Fair => 'Fair',
            self::Watch => 'Watch',
            self::Critical => 'Critical',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Strong => 'secondary',
            self::Fair => 'outline',
            self::Watch => 'default',
            self::Critical => 'destructive',
        };
    }

    /**
     * Whether a seller in this band belongs on the moderator's review list.
     */
    public function needsReview(): bool
    {
        return match ($this) {
            self::Watch, self::Critical => true,
            default => false,
        };
    }

    /**
     * What staff should consider, worst case first.
     *
     * @return array<int, string>
     */
    public function recommendedActions(): array
    {
        return match ($this) {
            self::Strong => [],
            self::Fair => [
                'Nothing required. Check again if the dispute rate climbs.',
            ],
            self::Watch => [
                'Read the recent one- and two-star reviews for a common cause.',
                'Message the seller about the pattern before it becomes a suspension.',
                'Hold off on direct settlement until the score recovers.',
            ],
            self::Critical => [
                'Revert the seller to escrow settlement if they are on direct.',
                'Review open disputes and any listing accuracy complaints.',
                'Consider suspending the shop pending a conversation.',
            ],
        };
    }
}
