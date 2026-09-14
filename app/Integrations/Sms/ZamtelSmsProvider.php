<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsResult;
use App\Modules\Identity\Support\ZambianPhone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Zamtel SMS — the real thing.
 *
 * Everything MonaFind knows about Zamtel lives in this class and the config
 * behind it. Above the SmsProvider interface nothing has heard of a sender
 * ID, an API key or a bulk endpoint.
 *
 * ## Three things this class is careful about
 *
 * **Numbers.** Zamtel's gateway wants a bare MSISDN — 260977123456, no plus,
 * no spaces — while the platform stores E.164 and buyers type 0977123456.
 * Every number goes through ZambianPhone on the way out, so a message is
 * never lost to a leading zero.
 *
 * **Failure is a value, not an exception.** An SMS that the network refuses
 * is news the caller may want to record, but it is almost never a reason to
 * fail the thing that triggered it — nobody's order should roll back because
 * a text bounced. So a rejection comes back as `SmsResult::rejected()` with
 * the reason, and only the queue's own retry decides whether to try again.
 *
 * **Sending is not retried here.** The HTTP call is given one attempt and a
 * short timeout. A gateway that accepted a message and then timed out will
 * have sent it; retrying inside this method would text somebody twice, and
 * the queued notification above is already the retry mechanism.
 */
class ZamtelSmsProvider implements SmsProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $senderId,
        private readonly ?string $username = null,
        private readonly int $timeout = 15,
    ) {}

    public function send(string $recipient, string $message, ?string $reference = null, ?string $senderId = null): SmsResult
    {
        $msisdn = $this->msisdn($recipient);

        if ($msisdn === null) {
            return SmsResult::rejected($recipient, 'Not a valid Zambian mobile number.');
        }

        try {
            $response = $this->request()->post('/send', [
                'sender_id' => $senderId ?? $this->senderId,
                'recipient' => $msisdn,
                'message' => $message,
                'client_reference' => $reference,
            ]);
        } catch (ConnectionException $exception) {
            return SmsResult::rejected($recipient, $exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            return new SmsResult(
                recipient: $recipient,
                accepted: false,
                failureReason: $this->reason($body) ?? 'Zamtel returned HTTP '.$response->status().'.',
                raw: $body,
            );
        }

        return new SmsResult(
            recipient: $recipient,
            accepted: true,
            providerMessageId: $this->messageId($body),
            raw: $body,
        );
    }

    /**
     * One call per recipient.
     *
     * Zamtel does have a bulk endpoint, and it answers with a single verdict
     * for the batch. That is the wrong shape for this interface, which
     * promises a result per recipient — and a batch that half-failed would be
     * reported as a success for everybody in it. Bulk sending here is a
     * reminder loop over a few hundred sellers once a day, so the extra calls
     * cost nothing worth the ambiguity.
     *
     * @param  array<int, string>  $recipients
     * @return array<int, SmsResult>
     */
    public function sendBulk(array $recipients, string $message, ?string $reference = null, ?string $senderId = null): array
    {
        return array_map(
            fn (string $recipient): SmsResult => $this->send($recipient, $message, $reference, $senderId),
            array_values($recipients),
        );
    }

    public function defaultSenderId(): string
    {
        return $this->senderId;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->withHeaders(array_filter([
                'Authorization' => 'Bearer '.$this->apiKey,
                'X-Zamtel-Username' => $this->username,
            ]));
    }

    /**
     * E.164 in the database, bare MSISDN on the wire.
     */
    private function msisdn(string $recipient): ?string
    {
        $parsed = ZambianPhone::tryParse($recipient);

        return $parsed === null ? null : ZambianPhone::COUNTRY_CODE.$parsed->nationalNumber;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function messageId(array $body): ?string
    {
        foreach (['message_id', 'messageId', 'id'] as $key) {
            if (isset($body[$key]) && (is_string($body[$key]) || is_int($body[$key]))) {
                return (string) $body[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reason(array $body): ?string
    {
        foreach (['message', 'error', 'description'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
        }

        return null;
    }
}
