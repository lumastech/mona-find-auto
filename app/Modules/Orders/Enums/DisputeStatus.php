<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * Where a dispute stands with MonaFind.
 *
 * Deliberately separate from the order's own status. The order is Disputed
 * for the whole time the argument runs; this says whether anybody has picked
 * it up yet, which is what the moderator queue is sorted on.
 */
enum DisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::UnderReview => 'Under review',
            self::Resolved => 'Resolved',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'destructive',
            self::UnderReview => 'default',
            self::Resolved => 'secondary',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::Open->value, self::UnderReview->value];
    }
}
