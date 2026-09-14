<?php

declare(strict_types=1);

namespace App\Integrations\Sms\Data;

/**
 * The outcome of handing one message to the SMS provider.
 */
final readonly class SmsResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $recipient,
        public bool $accepted,
        public ?string $providerMessageId = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    public static function accepted(string $recipient, ?string $providerMessageId = null): self
    {
        return new self($recipient, true, $providerMessageId);
    }

    public static function rejected(string $recipient, string $failureReason): self
    {
        return new self($recipient, false, null, $failureReason);
    }
}
