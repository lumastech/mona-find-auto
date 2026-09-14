<?php

declare(strict_types=1);

namespace App\Modules\Payments\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Payments\Jobs\RevertRiskySellersToEscrow;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "We have taken a seller off direct settlement."
 *
 * Goes to Finance and platform admins, not to the seller. Staff need to know
 * before the seller asks why their payouts stopped, and a seller told by an
 * automated email that they are now a credit risk is a conversation better
 * had by a person.
 *
 * @see RevertRiskySellersToEscrow
 */
class SellerRevertedToEscrow extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly Seller $seller,
        private readonly float $disputeRate,
        private readonly float $threshold,
    ) {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::PaymentModeReverted;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->body())
            ->line('New orders for this shop are now held in escrow until the buyer confirms. Orders already paid are unaffected — each one carries the terms it was paid under.')
            ->action('Review this seller', route('admin.sellers.show', $this->seller));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payments.seller_reverted_to_escrow',
            'seller_id' => $this->seller->getKey(),
            'dispute_rate_percent' => round($this->disputeRate, 2),
            'threshold_percent' => $this->threshold,
            'title' => $this->subject(),
            'body' => $this->body(),
            'action_url' => route('admin.sellers.show', $this->seller),
            'action_label' => 'Review this seller',
        ];
    }

    private function subject(): string
    {
        return $this->seller->business_name.' has been moved back to escrow';
    }

    private function body(): string
    {
        return sprintf(
            'Their dispute rate reached %s%%, above the %s%% threshold for direct settlement.',
            number_format($this->disputeRate, 2),
            number_format($this->threshold, 2),
        );
    }
}
