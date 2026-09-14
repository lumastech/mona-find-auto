<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use App\Modules\Shopping\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A buyer has asked you for a price."
 *
 * Sent to the shop the request was addressed to. It leads with the quantity,
 * because that is the part that decides whether the seller cares: one of
 * something is a sale they already have a price for, and forty of it is a
 * conversation worth having.
 */
class QuotationRequestedNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly Quotation $quotation)
    {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::QuotationRequested;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Quote request: '.$this->quotation->product->name)
            ->line($this->body())
            ->when(
                filled($this->quotation->message),
                fn (MailMessage $mail): MailMessage => $mail->line('"'.$this->quotation->message.'"'),
            )
            ->action('Answer this request', route('seller.quotations.index'))
            ->line('Requests that go unanswered are the ones buyers stop sending.');
    }

    /**
     * Worth a text: an unanswered quote request is a sale that went to
     * whichever shop looked at their phone first.
     */
    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'MonaFind: %s Answer it here: %s',
            $this->body(),
            route('seller.quotations.index'),
        ))->reference('quotations:'.$this->quotation->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'quotation.requested',
            'quotation_id' => $this->quotation->getKey(),
            'product_id' => $this->quotation->product_id,
            'title' => 'Quote request: '.$this->quotation->product->name,
            'body' => $this->body(),
            'action_url' => route('seller.quotations.index'),
            'action_label' => 'Answer this request',
        ];
    }

    private function body(): string
    {
        return sprintf(
            'A buyer has asked what %d × %s would cost.',
            $this->quotation->quantity,
            $this->quotation->variant->displayName(),
        );
    }
}
