<?php

declare(strict_types=1);

namespace App\Support\Content;

/**
 * What the automatic screen found in a piece of text before anybody else read
 * it.
 *
 * Two kinds of finding, and callers handle them differently on purpose.
 *
 * Contact details are REDACTED and the text goes through. A buyer writing
 * "call me on 0977..." is usually trying to be helpful; the harm is the
 * number sitting where it should not be, and blanking it removes the harm
 * without throwing away something somebody took the trouble to write.
 *
 * Profanity REQUIRES REVIEW. There is no automatic fix for it — the platform
 * cannot know whether the word is aimed at a seller or at a gearbox — so a
 * person decides. What "requires review" costs depends on the caller: a
 * review waits for a moderator, a message is delivered and merely flagged,
 * because holding a conversation hostage to a word list would break the
 * conversation.
 */
enum ScreenFlag: string
{
    case Profanity = 'profanity';
    case PhoneNumber = 'phone_number';
    case EmailAddress = 'email_address';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Profanity => 'Possible profanity',
            self::PhoneNumber => 'Phone number redacted',
            self::EmailAddress => 'Email address redacted',
            self::Url => 'Link redacted',
        };
    }

    /**
     * Whether this finding needs a person to look at it.
     */
    public function requiresReview(): bool
    {
        return $this === self::Profanity;
    }
}
