<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Enums;

/**
 * Why somebody pressed "Report" on a review.
 *
 * A closed list rather than free text, because the queue is worked by
 * severity: "this is abusive" and "this is about the wrong part" are the same
 * button to a seller and very different jobs for a moderator. The details
 * field is where they explain themselves.
 */
enum ReportReason: string
{
    case Abusive = 'abusive';
    case Spam = 'spam';
    case Untrue = 'untrue';
    case PersonalInformation = 'personal_information';
    case WrongListing = 'wrong_listing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Abusive => 'Abusive or offensive',
            self::Spam => 'Spam or advertising',
            self::Untrue => 'Untrue — this did not happen',
            self::PersonalInformation => 'Contains personal information',
            self::WrongListing => 'About a different part or order',
            self::Other => 'Something else',
        };
    }

    /**
     * Whether a single report of this kind takes the review off the page
     * straight away.
     *
     * Abuse and leaked personal details are the two where waiting for a
     * moderator is itself the harm. Everything else stays up until somebody
     * has read it — a seller who disagrees with a review must not be able to
     * remove it by pressing a button.
     */
    public function hidesImmediately(): bool
    {
        return match ($this) {
            self::Abusive, self::PersonalInformation => true,
            default => false,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
        ], self::cases());
    }
}
