<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Integrations\Sms\Data\SmsResult;

/**
 * Outbound SMS. Zamtel in production, a log or array provider elsewhere.
 *
 * Implementations only hand the message to the network; delivery reporting
 * and retries belong to the queued notification that calls them.
 *
 * The sender ID is a parameter rather than purely configuration because it is
 * part of what was sent — a delivery investigation starts with "which sender
 * ID did that go out under" — and because a transactional code and an
 * operational notice need not share one. Passing null means "whatever is
 * configured", which is the normal case.
 */
interface SmsProvider
{
    /**
     * Send one message. `$reference` lets us tie a delivery report back to
     * the notification that produced it.
     */
    public function send(string $recipient, string $message, ?string $reference = null, ?string $senderId = null): SmsResult;

    /**
     * Send the same message to several recipients.
     *
     * @param  array<int, string>  $recipients
     * @return array<int, SmsResult>
     */
    public function sendBulk(array $recipients, string $message, ?string $reference = null, ?string $senderId = null): array;

    /**
     * The sender ID messages go out under when the caller does not name one.
     */
    public function defaultSenderId(): string;
}
