<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Notifications;

use App\Modules\Mechanics\Enums\EndorsementStatus;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What the shop said.
 *
 * Sent to the mechanic on every answer, withdrawals included. A badge coming
 * off a profile is something its owner has to be told about — they may be
 * telling customers it is there.
 */
class EndorsementDecided extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly MechanicEndorsement $endorsement,
        private readonly EndorsementStatus $status,
        private readonly ?string $reason = null,
    ) {
        $this->onQueue((string) config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::EndorsementDecided;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->when(
                filled($this->reason),
                fn (MailMessage $mail): MailMessage => $mail->line('They said: "'.$this->reason.'"'),
            )
            ->action('Open your profile', route('mechanics.apply'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mechanic.endorsement.'.$this->status->value,
            'endorsement_id' => $this->endorsement->getKey(),
            'seller' => $this->endorsement->seller->business_name,
            'title' => $this->title(),
            'body' => $this->body(),
            'action_url' => route('mechanics.apply'),
            'action_label' => 'Open your profile',
        ];
    }

    private function title(): string
    {
        $business = $this->endorsement->seller->business_name;

        return match ($this->status) {
            EndorsementStatus::Endorsed => $business.' has endorsed you',
            EndorsementStatus::Declined => $business.' declined your request',
            EndorsementStatus::Revoked => $business.' has withdrawn their endorsement',
            EndorsementStatus::Requested => 'Your endorsement request was sent',
        };
    }

    private function body(): string
    {
        return match ($this->status) {
            EndorsementStatus::Endorsed => 'Their name now shows as a badge on your public profile.',
            EndorsementStatus::Declined => 'You can ask them again later, or ask another shop you have worked with.',
            EndorsementStatus::Revoked => 'The badge has been removed from your profile.',
            EndorsementStatus::Requested => 'We will let you know as soon as they answer.',
        };
    }
}
