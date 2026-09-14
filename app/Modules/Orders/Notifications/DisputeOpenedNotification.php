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
 * "A buyer has raised a problem, and your money is on hold."
 *
 * Sent to the seller. It says the hold out loud because that is the part they
 * will care about and the part they would otherwise discover as a payout that
 * did not arrive.
 */
class DisputeOpenedNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly OrderDispute $dispute) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::DisputeOpened;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Dispute opened on order '.$this->dispute->order->number)
            ->line(sprintf('The buyer reported: %s.', $this->dispute->reason->label()))
            ->line($this->dispute->details)
            ->line('This order will not complete and no payout will be released until MonaFind has looked at it.')
            ->action('Open the order', url('/seller/orders/'.$this->dispute->order->number));
    }

    /**
     * The seller's money has stopped moving; that is the part worth a text.
     *
     * The buyer's account of what went wrong is deliberately not in it — a
     * complaint quoted into a text message is a complaint answered in anger,
     * and the dispute view is where both sides see the same record.
     */
    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'MonaFind: a dispute was opened on order %s (%s). No payout will be released until it is decided.',
            $this->dispute->order->number,
            $this->dispute->reason->label(),
        ))->reference('disputes:'.$this->dispute->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::DisputeOpened->value,
            'order_number' => $this->dispute->order->number,
            'reason' => $this->dispute->reason->value,
            'details' => $this->dispute->details,
            'title' => 'Dispute opened on order '.$this->dispute->order->number,
            'body' => sprintf('The buyer reported: %s.', $this->dispute->reason->label()),
            'action_url' => url('/seller/orders/'.$this->dispute->order->number),
            'action_label' => 'Open the order',
        ];
    }
}
