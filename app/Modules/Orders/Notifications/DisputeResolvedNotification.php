<?php

declare(strict_types=1);

namespace App\Modules\Orders\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use App\Modules\Orders\Models\OrderDispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The decision, to both parties.
 *
 * The same message to the buyer and the seller, deliberately. A dispute
 * decided differently in the two accounts of it is a dispute that gets
 * reopened, and the one thing both sides must be able to agree on afterwards
 * is what was decided — so the outcome, the amount and the moderator's reason
 * go out verbatim to each.
 *
 * Worth a text. This is the end of a process somebody has been waiting on,
 * usually with money in the middle of it.
 */
class DisputeResolvedNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly OrderDispute $dispute) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::DisputeResolved;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Dispute decided for order '.$this->dispute->order->number)
            ->line($this->outcome());

        if (filled($this->dispute->resolution_note)) {
            $message->line('Moderator’s note: '.$this->dispute->resolution_note);
        }

        return $message->action('See the order', route('orders.show', $this->dispute->order));
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make('MonaFind: '.$this->outcome())
            ->reference('dispute:'.$this->dispute->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::DisputeResolved->value,
            'dispute_id' => $this->dispute->getKey(),
            'order_number' => $this->dispute->order->number,
            'resolution' => $this->dispute->resolution?->value,
            'refund_amount_ngwee' => $this->dispute->refund_amount_ngwee->ngwee,
            'title' => 'Dispute decided: order '.$this->dispute->order->number,
            'body' => $this->outcome(),
            'action_url' => route('orders.show', $this->dispute->order),
            'action_label' => 'See the order',
        ];
    }

    /**
     * What was decided, including the money — which is the part both sides
     * actually want and the part a vague notice makes them ring support for.
     */
    private function outcome(): string
    {
        $outcome = sprintf(
            'The dispute on order %s has been decided: %s.',
            $this->dispute->order->number,
            $this->dispute->resolution?->label() ?? 'closed',
        );

        return $this->dispute->refund_amount_ngwee->isPositive()
            ? $outcome.' Refund: '.$this->dispute->refund_amount_ngwee->format().'.'
            : $outcome;
    }
}
