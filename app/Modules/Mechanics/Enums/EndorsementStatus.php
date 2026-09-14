<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Enums;

/**
 * Where one mechanic-and-seller endorsement stands.
 *
 * requested → endorsed or declined, and endorsed → revoked. The badge on a
 * mechanic's profile is exactly the Endorsed rows and nothing else, so
 * declining and revoking do not need to delete anything: the row stays as the
 * record of what happened and simply stops counting.
 *
 * Revoked and Declined both allow another request later — a shop that turned
 * somebody down in March may endorse them in November — which is why the
 * transitions loop back to Requested rather than being terminal.
 */
enum EndorsementStatus: string
{
    /** The mechanic has asked; the shop has not answered. */
    case Requested = 'requested';

    /** The shop said yes. This is the state that puts a badge on the profile. */
    case Endorsed = 'endorsed';

    /** The shop said no. */
    case Declined = 'declined';

    /** The shop said yes once and has taken it back. */
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Awaiting reply',
            self::Endorsed => 'Endorsed',
            self::Declined => 'Declined',
            self::Revoked => 'Withdrawn',
        };
    }

    /**
     * Whether this endorsement shows as a badge on the mechanic's profile.
     */
    public function isActive(): bool
    {
        return $this === self::Endorsed;
    }

    /**
     * Whether the shop still owes an answer. What puts a row in the seller
     * portal's queue.
     */
    public function awaitsSeller(): bool
    {
        return $this === self::Requested;
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Endorsed => 'success',
            self::Declined, self::Revoked => 'destructive',
            self::Requested => 'secondary',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Endorsed, self::Declined],
            self::Endorsed => [self::Revoked],
            /* Both ends of a refusal may be asked again later. */
            self::Declined, self::Revoked => [self::Requested],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isActive()),
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
