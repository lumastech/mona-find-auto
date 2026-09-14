<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account holder that staff have put a warning on their account.
 */
class AccountWarnedNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly string $reason)
    {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::AccountWarned;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A warning has been recorded on your MonaFind account')
            ->line('MonaFind staff have recorded a warning on your account.')
            ->line('Reason: '.$this->reason)
            ->line('Your account still works as normal. Repeated issues can lead to suspension.')
            ->action('Review your account', route('profile.edit'));
    }

    /**
     * In the bell as well as in the inbox.
     *
     * A warning the person only ever received by email is a warning they can
     * plausibly say they never saw — and this is the notification whose
     * having-been-delivered matters most, because suspension follows it.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::AccountWarned->value,
            'title' => 'A warning has been recorded on your account',
            'body' => $this->reason,
            'action_url' => route('profile.edit'),
            'action_label' => 'Review your account',
        ];
    }
}
