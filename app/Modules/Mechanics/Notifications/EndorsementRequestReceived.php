<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Notifications;

use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A mechanic has asked you to vouch for them."
 *
 * Sent to the shop's account when a request lands. The request itself is the
 * row in the portal's queue; this is the ping that gets somebody to look at
 * it, so a backed-up queue costs a notification rather than the request.
 */
class EndorsementRequestReceived extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly MechanicEndorsement $endorsement)
    {
        $this->onQueue((string) config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::EndorsementRequested;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->when(
                filled($this->endorsement->message),
                fn (MailMessage $mail): MailMessage => $mail->line('They wrote: "'.$this->endorsement->message.'"'),
            )
            ->action('Read the request', $this->url())
            ->line('Endorsing a mechanic puts your shop\'s name on their profile, and you can withdraw it at any time.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mechanic.endorsement.requested',
            'endorsement_id' => $this->endorsement->getKey(),
            'mechanic' => $this->endorsement->profile->display_name,
            'title' => $this->title(),
            'body' => $this->body(),
            'action_url' => $this->url(),
            'action_label' => 'Read the request',
        ];
    }

    private function title(): string
    {
        return $this->endorsement->profile->display_name.' has asked for your endorsement';
    }

    private function body(): string
    {
        return sprintf(
            '%s is a MonaFind mechanic in %s and would like your shop to vouch for them.',
            $this->endorsement->profile->display_name,
            $this->endorsement->profile->locality(),
        );
    }

    private function url(): string
    {
        return route('seller.endorsements.index');
    }
}
