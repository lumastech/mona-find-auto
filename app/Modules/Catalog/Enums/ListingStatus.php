<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * Where a listing sits in its life.
 *
 * draft → pending_review → published, with unpublished, rejected and archived
 * reachable from the states the brief allows. The legal moves out of each
 * state are declared here rather than in the moderation service, so an
 * illegal move is one check in one place — the same shape the Sellers module
 * uses for verification.
 */
enum ListingStatus: string
{
    /** The seller is still writing it. Nobody else can see it. */
    case Draft = 'draft';

    /** Sent to MonaFind and waiting in the moderation queue. */
    case PendingReview = 'pending_review';

    /** Live on the storefront. */
    case Published = 'published';

    /** Taken down — by the seller, or automatically when they are suspended. */
    case Unpublished = 'unpublished';

    /** Turned down by a moderator, with reasons the seller can act on. */
    case Rejected = 'rejected';

    /** Retired by the seller. Kept for order history, never listed again. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
            self::Rejected => 'Rejected',
            self::Archived => 'Archived',
        };
    }

    /**
     * What the seller should do next, shown on the status chip in the portal.
     */
    public function sellerGuidance(): string
    {
        return match ($this) {
            self::Draft => 'Finish this listing and send it for review.',
            self::PendingReview => 'A moderator is checking this listing. No action needed.',
            self::Published => 'Live on MonaFind. Keep the stock confirmation up to date.',
            self::Unpublished => 'Hidden from buyers. Publish it again when you have stock.',
            self::Rejected => 'Read the reasons, fix what is flagged, and send it again.',
            self::Archived => 'Retired. Duplicate it if you want to sell this part again.',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Published => 'default',
            self::PendingReview => 'secondary',
            self::Rejected => 'destructive',
            self::Draft, self::Unpublished, self::Archived => 'outline',
        };
    }

    /**
     * Whether a buyer can see the listing at all.
     */
    public function isVisibleToBuyers(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether a moderator still owes this listing a decision.
     */
    public function isAwaitingModeration(): bool
    {
        return $this === self::PendingReview;
    }

    /**
     * Whether the seller may still change the listing's details.
     *
     * A listing under review is frozen: a moderator reading one version and
     * approving another is how bad listings get published.
     */
    public function isEditableBySeller(): bool
    {
        return in_array($this, [self::Draft, self::Rejected, self::Unpublished, self::Published], true);
    }

    /**
     * The states this one may legally move to.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Archived],
            self::PendingReview => [self::Published, self::Rejected, self::Draft, self::Archived],
            /* A published listing can be pulled, retired, or edited back into review. */
            self::Published => [self::Unpublished, self::PendingReview, self::Archived],
            self::Unpublished => [self::Published, self::PendingReview, self::Archived],
            /* A rejected listing is fixed and re-submitted rather than edited live. */
            self::Rejected => [self::PendingReview, self::Draft, self::Archived],
            /* Archived is the end of the line; order history still points at it. */
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * The statuses a buyer's queries are restricted to.
     *
     * @return array<int, string>
     */
    public static function publicValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isVisibleToBuyers());
    }

    /**
     * The statuses the moderation queue is built from.
     *
     * @return array<int, string>
     */
    public static function queueValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isAwaitingModeration());
    }

    /**
     * @return array<int, array{value: string, label: string, guidance: string, variant: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
            'guidance' => $status->sellerGuidance(),
            'variant' => $status->badgeVariant(),
        ], self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * @param  callable(self): bool  $matches
     * @return array<int, string>
     */
    private static function valuesWhere(callable $matches): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), $matches),
        ));
    }
}
