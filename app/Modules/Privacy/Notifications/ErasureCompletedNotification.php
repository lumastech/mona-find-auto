<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Privacy\Models\ErasureRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The last message this address will receive.
 *
 * ## Deliberately not queued
 *
 * Every other notification on the platform implements ShouldQueue. This one
 * must not: it is sent moments before the email address it is addressed to
 * becomes a tombstone. A queued job would be picked up after the erasure had
 * run and would post to `erased-91@erased.invalid`.
 *
 * Sending it inline costs the erasure a few hundred milliseconds and is the
 * only way the person actually hears that it happened.
 */
class ErasureCompletedNotification extends Notification implements PlatformNotification
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly ErasureRequest $request) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::ErasureCompleted;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your MonaFind account has been deleted')
            ->line('Your MonaFind account has been deleted and the personal data we held about you has been removed. You will not hear from us again at this address.')
            ->line('What we have kept, and why:')
            ->line('• Orders, payments, invoices and accounting entries. Zambian tax and company law requires us to keep these. They no longer carry your name, phone number or address.')
            ->line('• A record that a deletion was requested and carried out, which is how we can demonstrate we did what you asked.')
            ->line(sprintf(
                'Your request was made on %s and completed today.',
                $this->request->requested_at->timezone(config('monafind.display_timezone'))->format('j F Y'),
            ))
            ->line('If you want to use MonaFind again you are welcome to, but you will need to register from the beginning — there is nothing left to restore.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::ErasureCompleted->value,
            'title' => 'Account deleted',
            'body' => 'Your account and personal data have been removed.',
        ];
    }
}
