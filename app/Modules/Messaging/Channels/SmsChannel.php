<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Channels;

use App\Contracts\SmsProvider;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Support\SmsMessage;
use Illuminate\Notifications\Notification;

/**
 * The text-message channel.
 *
 * Until now this codebase sent SMS from purpose-built queued jobs — one per
 * kind of message, each resolving SmsProvider itself. That worked while there
 * were two of them; it does not survive a preference screen, because a person
 * turning off "order progress" texts has no way to reach a job that never
 * asked. So SMS is a channel now, routed by the same rules as mail and the
 * bell, and the notification supplies the copy through `toSms()`.
 *
 * A notification reaches here only if the router put SMS in its list, which
 * already required the recipient to have a verified number — so the guard
 * below is a belt-and-braces check for an on-demand notification routed by
 * hand, not the place the preference rule lives.
 */
class SmsChannel
{
    public function __construct(private readonly SmsProvider $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $recipient = $this->recipient($notifiable);

        if ($recipient === null) {
            return;
        }

        $message = $notification->toSms($notifiable);

        if (is_string($message)) {
            $message = SmsMessage::make($message);
        }

        if (! $message instanceof SmsMessage || trim($message->content) === '') {
            return;
        }

        $this->sms->send(
            recipient: $recipient,
            message: $message->body(),
            reference: $message->reference ?? $this->defaultReference($notification),
            senderId: $message->senderId,
        );
    }

    /**
     * The number to text: whatever `routeNotificationForSms()` says, or the
     * account's verified phone.
     */
    private function recipient(object $notifiable): ?string
    {
        $route = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('sms')
            : null;

        $route ??= $notifiable->phone ?? null;

        return is_string($route) && trim($route) !== '' ? trim($route) : null;
    }

    /**
     * A delivery report with no reference is a delivery report nobody can
     * use, so fall back to the catalogue key rather than sending none.
     */
    private function defaultReference(Notification $notification): string
    {
        return $notification instanceof PlatformNotification
            ? $notification->notificationEvent()->value
            : class_basename($notification);
    }
}
