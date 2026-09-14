<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsResult;
use App\Integrations\Support\RecordsCalls;
use Illuminate\Support\Str;

/**
 * Keeps every message in memory instead of sending it. Tests assert against
 * messagesTo() rather than mocking the interface by hand.
 *
 * The recorded payload includes the sender ID as resolved — the configured
 * one when the caller did not name one — because "what sender ID did that go
 * out under" is a question worth being able to answer in a test as well as in
 * a delivery investigation.
 */
class FakeSmsProvider implements SmsProvider
{
    use RecordsCalls;

    /** @var array<int, array{recipient: string, message: string, reference: string|null, sender_id: string}> */
    private array $messages = [];

    private bool $shouldFail = false;

    public function send(string $recipient, string $message, ?string $reference = null, ?string $senderId = null): SmsResult
    {
        $payload = [
            'recipient' => $recipient,
            'message' => $message,
            'reference' => $reference,
            'sender_id' => $senderId ?? $this->defaultSenderId(),
        ];

        $this->recordCall(__FUNCTION__, $payload);

        $this->messages[] = $payload;

        if ($this->shouldFail) {
            return SmsResult::rejected($recipient, 'Rejected by fake SMS provider.');
        }

        return SmsResult::accepted($recipient, 'fake_sms_'.Str::lower(Str::random(12)));
    }

    /**
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
        return (string) config('integrations.sms.sender_id', 'MonaFind');
    }

    /**
     * Every message sent so far, oldest first.
     *
     * @return array<int, array{recipient: string, message: string, reference: string|null, sender_id: string}>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return array<int, array{recipient: string, message: string, reference: string|null, sender_id: string}>
     */
    public function messagesTo(string $recipient): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (array $message): bool => $message['recipient'] === $recipient,
        ));
    }

    public function failNextMessages(bool $shouldFail = true): self
    {
        $this->shouldFail = $shouldFail;

        return $this;
    }

    public function forgetMessages(): void
    {
        $this->messages = [];
        $this->forgetCalls();
    }
}
