<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account holder that their account was suspended, reinstated or
 * closed, and why.
 *
 * The reason is never withheld: somebody who cannot trade needs to know what
 * to fix or what to appeal.
 */
class AccountStatusChangedNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly AccountStatus $status,
        private readonly string $reason,
    ) {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::AccountStatusChanged;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->line($this->opening())
            ->line('Reason: '.$this->reason);

        return match ($this->status) {
            AccountStatus::Suspended => $message->line('Reply to this email to appeal.'),
            AccountStatus::Closed => $message->line('Existing orders will be settled as normal. Reply to this email if you believe this is a mistake.'),
            default => $message->action('Go to MonaFind', route('home')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::AccountStatusChanged->value,
            'status' => $this->status->value,
            'title' => $this->subject(),
            'body' => $this->opening().' Reason: '.$this->reason,
            'action_url' => route('home'),
            'action_label' => 'Go to MonaFind',
        ];
    }

    private function subject(): string
    {
        return match ($this->status) {
            AccountStatus::Suspended => 'Your MonaFind account has been suspended',
            AccountStatus::Closed => 'Your MonaFind account has been closed',
            AccountStatus::Active => 'Your MonaFind account is active again',
            AccountStatus::Pending => 'Your MonaFind account needs verification',
        };
    }

    private function opening(): string
    {
        return match ($this->status) {
            AccountStatus::Suspended => 'Your account has been suspended and you cannot buy or sell until it is reinstated.',
            AccountStatus::Closed => 'Your account has been closed.',
            AccountStatus::Active => 'Your account has been reinstated and you can use MonaFind as normal.',
            AccountStatus::Pending => 'Your account is waiting on phone verification.',
        };
    }
}
