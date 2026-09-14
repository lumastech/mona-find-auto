<?php

declare(strict_types=1);

namespace App\Modules\Orders\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Enums\PaymentMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The buyer's receipt.
 *
 * The seller's half of a settled payment is OrderPaidNotification, which is a
 * call to action with a deadline on it. This is the other half and a
 * different kind of message: proof that money left the buyer's account, and a
 * plain statement of where it is now.
 *
 * Which is why the escrow sentence is here. A buyer who does not know MonaFind
 * is holding their money is a buyer who thinks they have paid a stranger —
 * and that is the single most common reason somebody rings support after
 * paying. It says so in the buyer's own terms: we hold it until you confirm.
 */
class PaymentReceivedNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly Order $order) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::PaymentReceived;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment received for order '.$this->order->number)
            ->line(sprintf(
                'We have received %s for your order with %s.',
                $this->order->total_ngwee->format(),
                $this->order->seller->business_name,
            ))
            ->line($this->custodyLine())
            ->action('Track your order', route('orders.show', $this->order));
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'MonaFind: payment of %s received for order %s. %s',
            $this->order->total_ngwee->format(),
            $this->order->number,
            $this->custodyLine(),
        ))->reference('payment:'.$this->order->number);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::PaymentReceived->value,
            'order_number' => $this->order->number,
            'total_ngwee' => $this->order->total_ngwee->ngwee,
            'title' => 'Payment received for '.$this->order->number,
            'body' => $this->custodyLine(),
            'action_url' => route('orders.show', $this->order),
            'action_label' => 'Track your order',
        ];
    }

    /**
     * Where the money is now, in the buyer's terms.
     *
     * Read off the order's SNAPSHOT rather than the seller's live mode: the
     * order was paid under whichever arrangement was in force at the time,
     * and a receipt that changes its story when an admin changes a setting is
     * not a receipt.
     */
    private function custodyLine(): string
    {
        return $this->order->payment_mode === PaymentMode::Direct
            ? 'The seller has been paid directly. Contact them, or open a dispute, if anything is wrong.'
            : 'MonaFind is holding your money until you confirm you have the part.';
    }
}
