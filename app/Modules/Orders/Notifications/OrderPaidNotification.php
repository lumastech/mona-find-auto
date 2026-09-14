<?php

declare(strict_types=1);

namespace App\Modules\Orders\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use App\Modules\Orders\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Somebody has paid you; you have a day to say yes."
 *
 * Sent to the seller the moment a payment settles, because the
 * seller-confirmation window starts running immediately and an auto-cancelled
 * order is a lost sale for them and a refund for the platform to process.
 * The deadline is stated in the message rather than implied.
 */
class OrderPaidNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly Order $order) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::OrderPlaced;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $due = $this->order->confirm_due_at?->timezone(
            (string) config('monafind.display_timezone', 'Africa/Lusaka'),
        );

        $mail = (new MailMessage)
            ->subject('New paid order '.$this->order->number)
            ->line(sprintf(
                'You have a paid order for %s.',
                $this->order->total_ngwee->format(),
            ));

        /* The deadline only exists once the order's terms have been frozen. */
        if ($due !== null) {
            $mail->line(sprintf(
                'Confirm it by %s or it is cancelled automatically and the buyer is refunded.',
                $due->format('j M Y, H:i'),
            ));
        }

        return $mail->action('Open the order', url('/seller/orders/'.$this->order->number));
    }

    /**
     * The one fact a seller standing behind a counter needs: money has
     * arrived, and there is a clock on it.
     */
    public function toSms(object $notifiable): SmsMessage
    {
        $due = $this->order->confirm_due_at?->timezone(
            (string) config('monafind.display_timezone', 'Africa/Lusaka'),
        );

        return SmsMessage::make(sprintf(
            'MonaFind: new paid order %s for %s.%s',
            $this->order->number,
            $this->order->total_ngwee->format(),
            $due === null ? '' : ' Confirm by '.$due->format('j M, H:i').' or it is cancelled.',
        ))->reference('orders:'.$this->order->number);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order.paid',
            'order_number' => $this->order->number,
            'total_ngwee' => $this->order->total_ngwee->ngwee,
            'confirm_due_at' => $this->order->confirm_due_at?->toIso8601String(),
        ];
    }
}
