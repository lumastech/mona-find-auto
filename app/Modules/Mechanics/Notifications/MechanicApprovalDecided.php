<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Notifications;

use App\Modules\Mechanics\Enums\MechanicStatus;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What MonaFind decided about a mechanic's profile.
 *
 * Approval, rejection and suspension in one notification, because all three
 * say the same kind of thing — here is where your profile stands and here is
 * what to do next — and a rejection whose reason was in a different email
 * from the decision is a support ticket waiting to happen.
 */
class MechanicApprovalDecided extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly MechanicProfile $profile,
        private readonly MechanicStatus $status,
        private readonly ?string $reason = null,
    ) {
        $this->onQueue((string) config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::MechanicApprovalDecided;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->status->applicantGuidance())
            ->when(
                filled($this->reason),
                fn (MailMessage $mail): MailMessage => $mail->line('Reason: '.$this->reason),
            )
            ->action($this->actionLabel(), $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mechanic.'.$this->status->value,
            'mechanic_profile_id' => $this->profile->getKey(),
            'title' => $this->title(),
            'body' => $this->status->applicantGuidance(),
            'reason' => $this->reason,
            'action_url' => $this->url(),
            'action_label' => $this->actionLabel(),
        ];
    }

    private function title(): string
    {
        return match ($this->status) {
            MechanicStatus::Approved => 'Your mechanic profile is live',
            MechanicStatus::Rejected => 'Your mechanic profile was not approved',
            MechanicStatus::Suspended => 'Your mechanic profile has been taken down',
            default => 'Your mechanic profile has been updated',
        };
    }

    private function actionLabel(): string
    {
        return $this->status === MechanicStatus::Approved ? 'View your profile' : 'Open your profile';
    }

    private function url(): string
    {
        return $this->status === MechanicStatus::Approved
            ? route('mechanics.show', $this->profile)
            : route('mechanics.apply');
    }
}
