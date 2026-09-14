<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Notifications;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\StockLevel;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You are running out of this."
 *
 * Sent once per crossing, not once per sale: a variant that falls to its
 * threshold is announced, and stays quiet until it has climbed back above the
 * threshold and fallen again. A shop selling briskly should not be emailed
 * about the same shelf four times in an afternoon.
 */
class LowStockAlert extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly ProductVariant $variant,
        private readonly StockLevel $level,
    ) {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::StockLow;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->line($this->body());

        if ($this->level === StockLevel::OutOfStock) {
            $message->line('Buyers can still find the listing, but they cannot order it until you add stock.');
        }

        return $message->action('Update this stock', route('seller.stock.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'stock.low_stock_alert',
            'product_id' => $this->variant->product_id,
            'variant_id' => $this->variant->getKey(),
            'sku' => $this->variant->sku,
            'quantity' => $this->variant->quantity,
            'level' => $this->level->value,
            'title' => $this->subject(),
            'body' => $this->body(),
            'action_url' => route('seller.stock.index'),
            'action_label' => 'Update this stock',
        ];
    }

    private function subject(): string
    {
        return $this->level === StockLevel::OutOfStock
            ? 'Out of stock: '.$this->variant->product->name
            : 'Running low: '.$this->variant->product->name;
    }

    private function body(): string
    {
        return $this->level === StockLevel::OutOfStock
            ? sprintf('%s (%s) has sold out.', $this->variant->product->name, $this->variant->sku)
            : sprintf(
                '%s (%s) is down to %d.',
                $this->variant->product->name,
                $this->variant->sku,
                $this->variant->quantity,
            );
    }
}
