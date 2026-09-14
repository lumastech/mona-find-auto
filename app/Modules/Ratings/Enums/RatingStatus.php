<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Enums;

use App\Support\Content\ScreenResult;

/**
 * Where a rating stands with moderation.
 *
 * Three states and one asymmetry worth stating plainly: a rating that is
 * waiting to be looked at still counts towards the seller's trust score, and
 * a hidden one does not. Holding a review back from the page while a person
 * reads it is a publishing decision; refusing to count it before anyone has
 * found anything wrong with it would let a seller suppress bad reviews simply
 * by reporting all of them.
 */
enum RatingStatus: string
{
    /** Screened clean, or restored by staff. On the page. */
    case Published = 'published';

    /** Flagged by the automatic screen or reported. Off the page, still counted. */
    case PendingReview = 'pending_review';

    /** Staff took it down, with a reason. Off the page and out of the aggregate. */
    case Hidden = 'hidden';

    /**
     * The state a newly submitted rating carrying this screen result takes.
     *
     * The mapping lives here rather than on ScreenResult: the shared screen
     * reports what it found, and what that costs is this module's rule —
     * Messaging reads the same result and delivers the message regardless.
     */
    public static function forScreen(ScreenResult $screened): self
    {
        return $screened->requiresReview() ? self::PendingReview : self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::PendingReview => 'In review',
            self::Hidden => 'Hidden',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Published => 'secondary',
            self::PendingReview => 'default',
            self::Hidden => 'destructive',
        };
    }

    /**
     * Whether this rating appears on a public page.
     */
    public function isVisible(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether this rating's stars count towards an aggregate.
     *
     * @see self — the docblock above explains why PendingReview counts.
     */
    public function countsTowardsAggregate(): bool
    {
        return $this !== self::Hidden;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], self::cases());
    }
}
