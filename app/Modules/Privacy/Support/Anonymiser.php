<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Support;

use App\Models\User;

/**
 * The tombstone values an erased account leaves behind.
 *
 * Every source anonymises through this one object so that an erased buyer
 * looks the same everywhere — one recognisable shape a support agent can read
 * off a screen, rather than a null here, an empty string there and "deleted
 * user" somewhere else.
 *
 * ## Why the placeholders are unique per account
 *
 * `email` and `phone` are unique columns. Writing the same literal into every
 * erased row would collide on the second erasure, and the failure would
 * surface as an unrelated-looking constraint violation months later. Each
 * tombstone therefore carries the account's id — which is not personal data:
 * it is a number the platform assigned, and it is already written into every
 * order, ledger line and audit row that has to survive.
 *
 * ## The token is a one-way door
 *
 * There is no reversal. Nothing stores a mapping from the tombstone back to
 * the original values, because a record that could undo an erasure would mean
 * the erasure had not happened.
 */
final readonly class Anonymiser
{
    public const REDACTED = '[redacted]';

    public function __construct(private int $userId) {}

    public static function for(User $user): self
    {
        return new self((int) $user->getKey());
    }

    /**
     * A unique, obviously-fake email for the users table.
     *
     * The domain is reserved by RFC 2606 for exactly this: it can never be
     * registered, so an erased address cannot accidentally become a real
     * mailbox somebody else owns.
     */
    public function email(): string
    {
        return sprintf('erased-%d@erased.invalid', $this->userId);
    }

    /**
     * A unique placeholder for the phone column.
     *
     * Deliberately not a valid E.164 number and not parseable by
     * ZambianPhone, so that anything trying to text an erased account fails
     * at the number rather than sending a message into the void.
     */
    public function phone(): string
    {
        return sprintf('erased-%d', $this->userId);
    }

    /**
     * The display name an erased account shows under.
     */
    public function name(): string
    {
        return 'Former MonaFind user';
    }

    public function firstName(): string
    {
        return 'Former';
    }

    public function lastName(): string
    {
        return 'user';
    }

    /**
     * For a free-text column that has to keep its row — a review body, a
     * dispute description — where the text itself was the personal data.
     */
    public function text(): string
    {
        return self::REDACTED;
    }

    /**
     * Free text that may be null. Keeps null as null rather than replacing an
     * absence with a redaction, which would claim something had been there.
     */
    public function textOrNull(?string $value): ?string
    {
        return $value === null || $value === '' ? $value : self::REDACTED;
    }

    public function userId(): int
    {
        return $this->userId;
    }
}
