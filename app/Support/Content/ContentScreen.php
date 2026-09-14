<?php

declare(strict_types=1);

namespace App\Support\Content;

/**
 * The automatic pass over text a buyer or seller typed, before anybody else
 * reads it.
 *
 * It does two different jobs and it is worth being clear which is which.
 *
 * It REDACTS contact details — phone numbers, email addresses, links — and
 * lets the text through. People write "call me on 0977..." constantly and
 * mean nothing by it; the harm is the number living somewhere it should not,
 * and blanking it removes the harm without discarding something somebody
 * wrote. MonaFind holds payment in escrow, so text that quietly teaches
 * people to deal off-platform also costs the buyer the protection they paid
 * for.
 *
 * It QUEUES profanity, because there is no automatic fix. The platform cannot
 * tell whether the word is aimed at a seller or at a gearbox, so a person
 * decides and the text waits — and in Ratings the seller's trust score keeps
 * counting it in the meantime, so this is not a lever for suppressing bad
 * news.
 *
 * It lives in the shared kernel rather than in a module because two places
 * now screen free text — reviews and messages — and the rule about what a
 * phone number looks like in Zambia should have one implementation rather
 * than two that quietly diverge. The same reasoning put ContactMask here.
 * What each caller DOES with the verdict still belongs to the caller:
 * Ratings holds a flagged review for a moderator, Messaging delivers a
 * redacted message and never blocks one.
 *
 * The word list is a setting rather than a constant. It is the kind of thing
 * that needs changing on a Tuesday afternoon without a deployment, and the
 * words that matter here are local.
 */
class ContentScreen
{
    /**
     * The shortest and longest digit counts a Zambian number can have once
     * the spacing is stripped: 0977123456 is ten, +260977123456 is twelve.
     */
    private const PHONE_MIN_DIGITS = 9;

    private const PHONE_MAX_DIGITS = 13;

    private const REDACTION = '[removed]';

    /**
     * Screen a body of text. A null or blank body is trivially clean — a
     * rating is allowed to be stars and nothing else.
     */
    public function screen(?string $body): ScreenResult
    {
        if (blank($body)) {
            return ScreenResult::clean($body === null ? null : trim($body));
        }

        $text = trim($body);
        $flags = [];

        $text = $this->redactEmails($text, $flags);
        $text = $this->redactUrls($text, $flags);
        $text = $this->redactPhoneNumbers($text, $flags);

        if ($this->containsProfanity($text)) {
            $flags[] = ScreenFlag::Profanity;
        }

        return new ScreenResult($text, $flags);
    }

    /**
     * The configured word list, lower-cased.
     *
     * @return array<int, string>
     */
    public function profanityTerms(): array
    {
        $terms = settings('content.profanity_terms', []);

        if (! is_array($terms)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $term): string => mb_strtolower(trim((string) $term)),
            $terms,
        ), static fn (string $term): bool => $term !== ''));
    }

    /**
     * @param  array<int, ScreenFlag>  $flags
     */
    private function redactEmails(string $text, array &$flags): string
    {
        return $this->replace(
            '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u',
            $text,
            $flags,
            ScreenFlag::EmailAddress,
        );
    }

    /**
     * Links, including the bare "shop.co.zm" form people actually type.
     *
     * @param  array<int, ScreenFlag>  $flags
     */
    private function redactUrls(string $text, array &$flags): string
    {
        $text = $this->replace('#\b(?:https?://|www\.)\S+#iu', $text, $flags, ScreenFlag::Url);

        return $this->replace(
            '#\b[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.(?:com|net|org|zm|co\.zm|io|shop)\b#iu',
            $text,
            $flags,
            ScreenFlag::Url,
        );
    }

    /**
     * Digit runs that look like a Zambian phone number, however they are
     * spaced.
     *
     * Matched loosely and then judged, rather than matched precisely: people
     * write "+260 977 123 456", "0977-123-456" and "0977.123.456", and a
     * pattern strict enough to be readable would miss two of the three.
     *
     * The judgement is the prefix, not just the length, and that is a
     * deliberate trade against part numbers. "90919-01253" is ten digits and
     * is the single most useful thing an auto-parts review or message can
     * contain; redacting it to protect against a number nobody typed would be
     * a bad bargain. So a run counts as a phone number only if it starts the
     * way Zambian numbers start — a leading zero, or the 260 country code.
     *
     * What this lets through is somebody writing "977123456" with the zero
     * dropped. That is the price, it is a form almost nobody types, and the
     * report button and the moderation queue are the backstop for the person
     * who is determined — no pattern stops "zero nine seven seven" anyway.
     *
     * @param  array<int, ScreenFlag>  $flags
     */
    private function redactPhoneNumbers(string $text, array &$flags): string
    {
        $found = false;

        $redacted = preg_replace_callback(
            '/\+?\d[\d\s().\-]{6,}\d/u',
            static function (array $matches) use (&$found): string {
                $digits = preg_replace('/\D/', '', $matches[0]) ?? '';
                $length = mb_strlen($digits);

                if ($length < self::PHONE_MIN_DIGITS || $length > self::PHONE_MAX_DIGITS) {
                    return $matches[0];
                }

                $isLocal = str_starts_with($digits, '0') && $length <= 10;
                $isInternational = str_starts_with($digits, '260');

                if (! $isLocal && ! $isInternational) {
                    return $matches[0];
                }

                $found = true;

                return self::REDACTION;
            },
            $text,
        );

        if ($found) {
            $flags[] = ScreenFlag::PhoneNumber;
        }

        return $redacted ?? $text;
    }

    private function containsProfanity(string $text): bool
    {
        $haystack = mb_strtolower($text);

        foreach ($this->profanityTerms() as $term) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, ScreenFlag>  $flags
     */
    private function replace(string $pattern, string $text, array &$flags, ScreenFlag $flag): string
    {
        $count = 0;
        $replaced = preg_replace($pattern, self::REDACTION, $text, -1, $count);

        if ($count > 0) {
            $flags[] = $flag;
        }

        return $replaced ?? $text;
    }
}
