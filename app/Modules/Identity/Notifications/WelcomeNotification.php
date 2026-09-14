<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Models\User;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one message a new account gets, and what is still outstanding on it.
 *
 * Not a marketing welcome. Registration on MonaFind leaves two things
 * half-done — a phone number that has been typed but not proven, and an email
 * address that has been typed but not clicked — and an account with either
 * outstanding cannot do what the person registered to do. So this says which
 * of the two are still open and links to them.
 *
 * The verification CODE is not here: that is OtpNotification, by text, and
 * keeping the two apart is what stops a code living in an email inbox.
 */
class WelcomeNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly User $user) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::AccountRegistered;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Welcome to MonaFind')
            ->greeting('Hello '.$this->user->first_name)
            ->line('Your MonaFind account is open. You can browse parts and shops straight away.');

        foreach ($this->outstanding() as $line) {
            $message->line($line);
        }

        return $message->action('Start looking for parts', route('home'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::AccountRegistered->value,
            'title' => 'Welcome to MonaFind',
            'body' => $this->outstanding() === []
                ? 'Your account is ready.'
                : implode(' ', $this->outstanding()),
            'action_url' => route('home'),
            'action_label' => 'Start looking for parts',
        ];
    }

    /**
     * What is still half-done, in the order it blocks things.
     *
     * @return array<int, string>
     */
    private function outstanding(): array
    {
        $lines = [];

        if ($this->user->phone_verified_at === null) {
            $lines[] = 'Verify your phone number to buy — we text you a six-digit code.';
        }

        if ($this->user->email_verified_at === null) {
            $lines[] = 'Confirm your email address so you can recover your account.';
        }

        return $lines;
    }
}
