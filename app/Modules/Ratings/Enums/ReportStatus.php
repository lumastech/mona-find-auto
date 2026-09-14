<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Enums;

/**
 * What a moderator made of a report.
 */
enum ReportStatus: string
{
    case Open = 'open';
    case Upheld = 'upheld';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Upheld => 'Upheld',
            self::Dismissed => 'Dismissed',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /**
     * The statuses that count as still open.
     *
     * Read by both RatingReport::scopeOpen() and the `whereHas` inside
     * Rating::scopeNeedingReview(), so the queue and the relation cannot come
     * to different conclusions about what is outstanding.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::Open->value];
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'default',
            self::Upheld => 'destructive',
            self::Dismissed => 'secondary',
        };
    }
}
