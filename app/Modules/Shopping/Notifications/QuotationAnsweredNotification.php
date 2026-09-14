<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The shop has come back to you."
 *
 * One notification for both answers, because from the buyer's side they are
 * the same moment. A quote carries the price and the date it stands until —
 * the date is not decoration, it is the reason to open the message today
 * rather than next week.
 */
class QuotationAnsweredNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly Quotation $quotation)
    {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::QuotationAnswered;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action('View your quotes', route('quotations.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'quotation.answered',
            'quotation_id' => $this->quotation->getKey(),
            'product_id' => $this->quotation->product_id,
            'status' => $this->quotation->status->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'action_url' => route('quotations.index'),
            'action_label' => 'View your quotes',
        ];
    }

    private function title(): string
    {
        return $this->quotation->status === QuotationStatus::Quoted
            ? 'You have a quote for '.$this->quotation->product->name
            : 'Quote request declined: '.$this->quotation->product->name;
    }

    private function body(): string
    {
        if ($this->quotation->status !== QuotationStatus::Quoted) {
            return sprintf(
                '%s will not quote for this request.%s',
                $this->quotation->seller->business_name,
                filled($this->quotation->decline_reason) ? ' "'.$this->quotation->decline_reason.'"' : '',
            );
        }

        return sprintf(
            '%s quoted %s each for %d, valid until %s.',
            $this->quotation->seller->business_name,
            $this->quotation->effectiveUnitPrice()->format(),
            $this->quotation->quantity,
            $this->quotation->valid_until?->format('j F Y') ?? 'further notice',
        );
    }
}
