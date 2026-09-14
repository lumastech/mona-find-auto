<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use App\Modules\Privacy\Models\ErasureRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account will be deleted on the 27th. Here is how to stop it."
 *
 * Sent the moment an erasure is requested, by email and SMS, and not
 * configurable away. The case it is really for is the one where the person
 * reading it did not ask: a stolen session requesting deletion is exactly the
 * kind of thing an account holder needs to hear about while there is still
 * time to act.
 */
class ErasureScheduledNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly ErasureRequest $request)
    {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::ErasureScheduled;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your MonaFind account is scheduled for deletion')
            ->line(sprintf(
                'We have received a request to delete your MonaFind account and everything personal we hold about you. This will happen on %s.',
                $this->eraseDate(),
            ))
            ->line('Until then nothing has been removed and you can stop this at any time by signing in and cancelling the request.')
            ->action('Cancel the deletion', route('privacy.index'))
            ->line('If you did not ask for this, cancel it now and change your password — somebody may have access to your account.')
            ->line('Records we are required by law to keep — your orders, payments and invoices — will be kept without your personal details attached to them.');
    }

    /**
     * Short, because it is an SMS. One fact and one instruction.
     */
    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'MonaFind: your account is set to be deleted on %s. If you did not ask for this, sign in and cancel it now.',
            $this->eraseDate(),
        ))->reference('privacy:erasure-scheduled');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::ErasureScheduled->value,
            'title' => 'Account deletion scheduled',
            'body' => sprintf('Your account is scheduled for deletion on %s.', $this->eraseDate()),
            'action_url' => route('privacy.index'),
            'action_label' => 'Cancel the deletion',
        ];
    }

    private function eraseDate(): string
    {
        return $this->request->erase_after
            ->timezone(config('monafind.display_timezone'))
            ->format('j F Y');
    }
}
