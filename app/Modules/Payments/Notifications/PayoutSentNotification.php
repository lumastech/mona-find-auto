<?php

declare(strict_types=1);

namespace App\Modules\Payments\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use App\Modules\Payments\Models\PayoutLine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your money has left MonaFind."
 *
 * Sent on SETTLEMENT, not on dispatch. A transfer that has been handed to
 * Lenco and not yet confirmed is money a seller cannot spend, and telling
 * them it has arrived before it has is how a shop ends up standing at a bank
 * counter asking about a payment that is still in flight.
 *
 * One of the few events worth a text by default. A seller waiting on a payout
 * is waiting on it to buy stock, and this is the message they would otherwise
 * ring support about.
 *
 * The account is named by its masked tail rather than in full: this is a
 * receipt, and a receipt that reprints the whole account number is a receipt
 * that should not be sitting in an inbox.
 */
class PayoutSentNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly PayoutLine $line) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::PayoutSent;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('MonaFind has paid you '.$this->line->amount_ngwee->format())
            ->line($this->body())
            ->line('Reference: '.$this->line->reference)
            ->action('See your earnings', route('seller.earnings.index'));
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'MonaFind: %s has been paid to your %s account. Ref %s.',
            $this->line->amount_ngwee->format(),
            $this->line->method?->label() ?? 'payout',
            $this->line->reference,
        ))->reference('payout:'.$this->line->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::PayoutSent->value,
            'payout_line_id' => $this->line->getKey(),
            'reference' => $this->line->reference,
            'amount_ngwee' => $this->line->amount_ngwee->ngwee,
            'title' => 'Payout sent: '.$this->line->amount_ngwee->format(),
            'body' => $this->body(),
            'action_url' => route('seller.earnings.index'),
            'action_label' => 'See your earnings',
        ];
    }

    private function body(): string
    {
        return sprintf(
            '%s has been paid to your %s account%s.',
            $this->line->amount_ngwee->format(),
            $this->line->method?->label() ?? 'payout',
            $this->line->account?->last_four !== null
                ? ' ending '.$this->line->account->last_four
                : '',
        );
    }
}
