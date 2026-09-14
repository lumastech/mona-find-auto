<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use Illuminate\Support\Str;

/**
 * One text message, as a notification hands it to the SMS channel.
 *
 * Deliberately thin. An SMS has no subject, no formatting and no second
 * paragraph, and every character costs — so the only decisions worth
 * modelling are what it says, what the delivery report should be tied back
 * to, and which sender ID it goes out under.
 */
final class SmsMessage
{
    /**
     * A single 7-bit GSM segment is 160 characters. Past that the network
     * splits the message and bills for each part, so the content is trimmed
     * to two segments: long enough for a real sentence with an order number
     * in it, short enough that nobody sends a paragraph by accident.
     */
    public const MAX_LENGTH = 306;

    public function __construct(
        public string $content,
        public ?string $reference = null,
        public ?string $senderId = null,
    ) {}

    public static function make(string $content): self
    {
        return new self($content);
    }

    /**
     * What the delivery report is filed under — usually the event key and the
     * subject's id, e.g. "orders.placed:MFA-1024".
     */
    public function reference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * Override the configured sender ID. Rarely wanted; here because a
     * transactional code and a marketing blast may not share one.
     */
    public function from(string $senderId): self
    {
        $this->senderId = $senderId;

        return $this;
    }

    /**
     * The body as it goes on the wire, collapsed to one line and capped.
     *
     * Newlines are stripped rather than preserved because a message composed
     * for email and reused here would otherwise arrive as a ragged block on a
     * feature phone.
     */
    public function body(): string
    {
        $content = trim((string) preg_replace('/\s+/u', ' ', $this->content));

        return Str::limit($content, self::MAX_LENGTH, preserveWords: true);
    }
}
