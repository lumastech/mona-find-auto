<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Notifications;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The part you were waiting for is back."
 *
 * Sent once per subscription, ever — the subscription row is marked spent
 * before this is queued, so a shelf that empties and refills twice in a day
 * does not send the same person the same message twice.
 *
 * It says the shop's name because that is what decides whether the buyer
 * bothers: a part being back at a yard in Kitwe is different news to somebody
 * in Lusaka.
 */
class BackInStockNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(private readonly ProductVariant $variant)
    {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::StockBackIn;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Back in stock: '.$this->variant->product->name)
            ->line($this->body())
            ->action('View the listing', route('listings.show', $this->variant->product))
            ->line('Stock moves quickly, so it is worth checking soon.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'stock.back_in_stock',
            'product_id' => $this->variant->product_id,
            'variant_id' => $this->variant->getKey(),
            'title' => 'Back in stock: '.$this->variant->product->name,
            'body' => $this->body(),
            'action_url' => route('listings.show', $this->variant->product),
            'action_label' => 'View the listing',
        ];
    }

    private function body(): string
    {
        return sprintf(
            '%s is available again from %s.',
            $this->variant->displayName(),
            $this->variant->product->seller->business_name,
        );
    }
}
