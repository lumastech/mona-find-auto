<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * Where a seller sits in the verification workflow.
 *
 * draft → submitted → under_review → inspection_scheduled → verified,
 * with rejected reachable from any state under review, and suspended
 * reachable from verified. The transitions allowed out of each state are
 * declared here rather than in the service, so an illegal move is one check
 * in one place.
 */
enum VerificationStatus: string
{
    /** Signing up. Nothing has been sent to MonaFind yet. */
    case Draft = 'draft';

    /** Sent for verification and waiting to be picked up by staff. */
    case Submitted = 'submitted';

    /** A moderator has the file open. */
    case UnderReview = 'under_review';

    /** Someone is going out to see the premises. */
    case InspectionScheduled = 'inspection_scheduled';

    /** Checked and badged. */
    case Verified = 'verified';

    /** Turned down, with a reason the seller can read. */
    case Rejected = 'rejected';

    /** Was verified, and has had the badge and the portal taken away. */
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::InspectionScheduled => 'Inspection scheduled',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * What a seller reading their own status card should be told next.
     */
    public function sellerGuidance(): string
    {
        return match ($this) {
            self::Draft => 'Finish your application and send it to MonaFind.',
            self::Submitted => 'We have your application. A reviewer will pick it up shortly.',
            self::UnderReview => 'A reviewer is checking your documents. We may call the number you gave us.',
            self::InspectionScheduled => 'We are arranging a visit to your premises. Keep your documents to hand.',
            self::Verified => 'You are verified. Your badge shows on your profile and every listing.',
            self::Rejected => 'Your application was not accepted. Read the reason, fix it, and send it again.',
            self::Suspended => 'Your account is suspended and your listings are hidden. Contact MonaFind support.',
        };
    }

    /**
     * The badge a buyer sees. Anything short of Verified says so plainly
     * rather than saying nothing.
     */
    public function publicLabel(): string
    {
        return $this === self::Verified ? 'Verified' : 'Not yet verified';
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether the seller's public profile and listings are visible at all.
     */
    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::InspectionScheduled, self::Verified], true);
    }

    /**
     * Whether the seller may open the seller portal. They can from the moment
     * they apply — policies, payout details and documents all have to be
     * manageable while the application is being reviewed.
     */
    public function grantsPortalAccess(): bool
    {
        return $this !== self::Suspended;
    }

    /**
     * Whether staff still have work to do on this application.
     */
    public function isInQueue(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::InspectionScheduled], true);
    }

    /**
     * The states this one may legally move to.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::UnderReview, self::InspectionScheduled, self::Verified, self::Rejected],
            self::UnderReview => [self::InspectionScheduled, self::Verified, self::Rejected],
            self::InspectionScheduled => [self::UnderReview, self::Verified, self::Rejected],
            /* A rejected application is re-submitted rather than edited in place. */
            self::Rejected => [self::Submitted],
            self::Verified => [self::Suspended],
            self::Suspended => [self::Verified],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * The statuses whose sellers appear on the storefront.
     *
     * @return array<int, string>
     */
    public static function publiclyVisibleValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isPubliclyVisible());
    }

    /**
     * The statuses that still need a decision from staff.
     *
     * @return array<int, string>
     */
    public static function queueValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isInQueue());
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

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
