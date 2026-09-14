<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes messages to the log instead of sending them — the default outside
 * production, so local and staging work never puts a charge on the SMS
 * account or texts a real customer.
 */
class LogSmsProvider implements SmsProvider
{
    public function send(string $recipient, string $message, ?string $reference = null, ?string $senderId = null): SmsResult
    {
        $messageId = 'log_sms_'.Str::lower(Str::random(12));

        Log::channel(config('logging.default'))->info('SMS (not sent — log provider)', [
            'recipient' => $recipient,
            'message' => $message,
            'reference' => $reference,
            'sender_id' => $senderId ?? $this->defaultSenderId(),
            'provider_message_id' => $messageId,
        ]);

        return SmsResult::accepted($recipient, $messageId);
    }

    public function defaultSenderId(): string
    {
        return (string) config('integrations.sms.sender_id', 'MonaFind');
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
}
