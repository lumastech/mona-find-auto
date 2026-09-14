<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Enums;

/**
 * Where a mechanic's profile sits in the approval workflow.
 *
 * draft → submitted → under_review → approved, with rejected reachable from
 * anywhere staff are looking at it and suspended reachable from approved.
 * The legal moves are declared here rather than in the service, so an
 * illegal one is a single check in a single place.
 *
 * The line that matters to everybody else is isPubliclyVisible(): only an
 * approved profile is a profile. A submitted one is an application, and an
 * application is not something a buyer may find, read, filter on or rate.
 * Unlike a seller — whose shop appears while the badge is pending — nothing
 * about a mechanic is public until a human has looked at the qualification
 * they claim.
 */
enum MechanicStatus: string
{
    /** Being filled in. Nothing has been sent to MonaFind yet. */
    case Draft = 'draft';

    /** Sent for approval and waiting to be picked up by staff. */
    case Submitted = 'submitted';

    /** A moderator has the application open. */
    case UnderReview = 'under_review';

    /** Checked and published. This is the only public state. */
    case Approved = 'approved';

    /** Turned down, with a reason the applicant can read. */
    case Rejected = 'rejected';

    /** Was approved, and has been taken down. */
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * What the mechanic reading their own status card should be told next.
     */
    public function applicantGuidance(): string
    {
        return match ($this) {
            self::Draft => 'Finish your profile and send it to MonaFind for approval.',
            self::Submitted => 'We have your profile. A reviewer will pick it up shortly.',
            self::UnderReview => 'A reviewer is checking your qualification and work history. We may call you.',
            self::Approved => 'Your profile is live in the mechanic directory.',
            self::Rejected => 'Your profile was not approved. Read the reason, fix it, and send it again.',
            self::Suspended => 'Your profile has been taken down. Contact MonaFind support.',
        };
    }

    /**
     * Whether the profile appears in the directory, on a profile page, in the
     * API, or anywhere else a member of the public can reach.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Approved;
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Whether the applicant may still edit what they wrote.
     *
     * Editing stops the moment it is sent: a reviewer must be reading the
     * same qualification the applicant submitted. A rejected profile reopens
     * so it can be fixed and sent again.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /**
     * Whether staff still have work to do on this application.
     */
    public function isInQueue(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview], true);
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected, self::Suspended => 'destructive',
            self::Submitted, self::UnderReview => 'secondary',
            self::Draft => 'outline',
        };
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
            self::Submitted => [self::UnderReview, self::Approved, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected],
            /* A rejected profile is corrected and sent again, not edited in place by staff. */
            self::Rejected => [self::Submitted],
            self::Approved => [self::Suspended],
            self::Suspended => [self::Approved],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * The statuses whose profiles the public may see.
     *
     * @return array<int, string>
     */
    public static function publiclyVisibleValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isPubliclyVisible());
    }

    /**
     * The statuses still waiting on a decision from staff.
     *
     * @return array<int, string>
     */
    public static function queueValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isInQueue());
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
