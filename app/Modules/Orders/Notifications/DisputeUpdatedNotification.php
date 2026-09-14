<?php

declare(strict_types=1);

namespace App\Modules\Orders\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Orders\Models\OrderDispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Something has moved on your dispute."
 *
 * Deliberately vague about what. A dispute is decided on evidence from both
 * sides, and a notification that reproduced the other party's new statement
 * would turn the process into an argument conducted by email — each side
 * answering the last thing they were sent rather than the moderator's
 * question.
 *
 * So it says that there is something new and where to read it. The dispute
 * view is the one place both parties and the moderator see the same record.
 */
class DisputeUpdatedNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly OrderDispute $dispute,
        private readonly string $summary,
    ) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::DisputeUpdated;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update on the dispute for order '.$this->dispute->order->number)
            ->line($this->summary)
            ->action('Open the dispute', route('orders.show', $this->dispute->order));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::DisputeUpdated->value,
            'dispute_id' => $this->dispute->getKey(),
            'order_number' => $this->dispute->order->number,
            'status' => $this->dispute->status->value,
            'title' => 'Dispute updated: order '.$this->dispute->order->number,
            'body' => $this->summary,
            'action_url' => route('orders.show', $this->dispute->order),
            'action_label' => 'Open the dispute',
        ];
    }
}
