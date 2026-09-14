<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Tries;

/**
 * A six-digit code, by text message.
 *
 * This replaces the hand-rolled SendOtpMessage job. The job was right while
 * SMS was something two places sent; it became wrong the moment people could
 * choose which notifications reach them, because a job that resolves
 * SmsProvider itself is a delivery nobody can configure — and a verification
 * code is precisely the delivery that must NOT be configurable away. Making
 * it a notification puts it inside the rule that says so, rather than outside
 * the rule and coincidentally correct.
 *
 * Sent on demand — `Notification::route('sms', $phone)` — rather than to a
 * User, because at registration there is no account yet, and at password
 * reset we deliberately do not want to look one up from an unauthenticated
 * request.
 *
 * SMS only, and no in-app copy on purpose: the bell is behind the sign-in the
 * code exists to get through, and a plaintext code sitting in the
 * notifications table forever is a credential at rest.
 *
 * The code itself lives only for the life of this notification — the database
 * keeps a hash — so it is deliberately not retried indefinitely. A code that
 * could not be delivered before it expired is worth less than the wait, and
 * the caller can always request another.
 */
#[Tries(3)]
class OtpNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly string $code,
        private readonly OtpPurpose $purpose,
        private readonly int $expiresInMinutes,
    ) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::Otp;
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make($this->purpose->message($this->code, $this->expiresInMinutes))
            ->reference('otp:'.$this->purpose->value);
    }

    /**
     * Stop trying once the code it carries has expired.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes($this->expiresInMinutes);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }
}
