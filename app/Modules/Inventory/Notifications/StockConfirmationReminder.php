<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Notifications;

use App\Modules\Inventory\Jobs\SendStockConfirmationReminders;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Is your stock still accurate?"
 *
 * Sent at day three, and again at day five with a sharper edge — by then the
 * listings are about to be labelled unconfirmed to buyers, and the seller
 * deserves to be told that in those words rather than in a reminder that
 * sounds identical to the first.
 *
 * Mail, in-app and SMS. The text is the channel that actually reaches a
 * Zambian parts seller during a working day — email is read in the evening if
 * at all — so this is one of the few events that spends money on one by
 * default. Which channels it really uses is the matrix's decision and then
 * the seller's; this class only writes the copy.
 *
 * @see SendStockConfirmationReminders
 */
class StockConfirmationReminder extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly Seller $seller,
        private readonly int $listingCount,
        private readonly int $daysSinceConfirmed,
        private readonly bool $isFinalWarning,
    ) {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::StockConfirmationReminder;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$this->seller->business_name)
            ->line($this->openingLine());

        if ($this->isFinalWarning) {
            $message->line('Buyers now see a "Stock unconfirmed" label on these listings, and they rank below shops that have confirmed.');
        }

        return $message
            ->action('Confirm my stock', route('seller.stock.index'))
            ->line('One tap confirms everything, or you can correct individual quantities on the way through.');
    }

    /**
     * Under 160 characters, with the one link that fixes it.
     *
     * Deliberately not a shortened version of the email. A seller reading
     * this is standing in a shop with a customer in front of them: it says
     * how many, what it costs them, and where to tap.
     */
    public function toSms(object $notifiable): SmsMessage
    {
        $text = $this->isFinalWarning
            ? sprintf(
                'MonaFind: %d of your listings are now labelled "stock unconfirmed" to buyers. Confirm to restore them: %s',
                $this->listingCount,
                route('seller.stock.index'),
            )
            : sprintf(
                'MonaFind: %d of your listings have not been confirmed for %d days. One tap keeps them ranking: %s',
                $this->listingCount,
                $this->daysSinceConfirmed,
                route('seller.stock.index'),
            );

        return SmsMessage::make($text)->reference('stock:reminder');
    }

    /**
     * The in-app copy. Kept to the two facts a seller acts on: how many, and
     * how overdue.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'stock.confirmation_reminder',
            'seller_id' => $this->seller->getKey(),
            'listing_count' => $this->listingCount,
            'days_since_confirmed' => $this->daysSinceConfirmed,
            'is_final_warning' => $this->isFinalWarning,
            'title' => $this->subject(),
            'body' => $this->openingLine(),
            'action_url' => route('seller.stock.index'),
            'action_label' => 'Confirm my stock',
        ];
    }

    private function subject(): string
    {
        return $this->isFinalWarning
            ? 'Your MonaFind listings are being marked unconfirmed'
            : 'Is your MonaFind stock still accurate?';
    }

    private function openingLine(): string
    {
        return sprintf(
            '%d %s you have not confirmed for %d days.',
            $this->listingCount,
            $this->listingCount === 1 ? 'listing' : 'listings',
            $this->daysSinceConfirmed,
        );
    }
}
