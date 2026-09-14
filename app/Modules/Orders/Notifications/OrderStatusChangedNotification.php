<?php

declare(strict_types=1);

namespace App\Modules\Orders\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The buyer's running commentary on their own order.
 *
 * Only the moves a buyer would want to hear about — a part going on the
 * counter, a parcel leaving, an order being cancelled out from under them.
 * Every intermediate state would be noise, and a buyer who learns to ignore
 * these will also ignore the one saying their order was cancelled.
 */
class OrderStatusChangedNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly OrderStatus $status,
    ) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::OrderStateChanged;
    }

    /**
     * The states worth telling a buyer about.
     *
     * Deliberately not every state. Paid has its own receipt, Disputed is the
     * buyer's own action, Closed is bookkeeping — and a buyer who learns to
     * ignore these will also ignore the one saying their order was cancelled.
     * What is left is the shop accepting, the part being ready, the parcel
     * leaving, and the three endings.
     *
     * @return array<int, OrderStatus>
     */
    public static function notifiableStates(): array
    {
        return [
            OrderStatus::SellerConfirmed,
            OrderStatus::ReadyForPickup,
            OrderStatus::Dispatched,
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('Order %s: %s', $this->order->number, $this->status->label()))
            ->line($this->status->buyerDescription())
            ->action('View your order', url('/orders/'.$this->order->number));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order.status',
            'order_number' => $this->order->number,
            'status' => $this->status->value,
            'headline' => $this->status->label(),
            'message' => $this->status->buyerDescription(),
        ];
    }
}
