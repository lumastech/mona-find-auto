<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Orders\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "That order is finished — how did it go?"
 *
 * Sent once, when the order completes, to whichever side is being asked. The
 * buyer's copy asks about the shop and the shop's copy asks about the buyer,
 * because the two ratings are different things and a message that said "leave
 * a review" would be ambiguous to a seller.
 *
 * There is no reminder. A review nobody wanted to write is not worth chasing,
 * and a marketplace that nags after every order teaches people to ignore it.
 */
class RatingInvitation extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly string $subjectName,
        private readonly bool $forSeller,
    ) {
        $this->onQueue((string) config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::RatingInvited;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action($this->forSeller ? 'Rate the buyer' : 'Write a review', $this->url())
            ->line('It takes a moment and it is what helps the next person choose.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'rating.invitation',
            'order_number' => $this->order->number,
            'title' => $this->title(),
            'body' => $this->body(),
            'action_url' => $this->url(),
            'action_label' => $this->forSeller ? 'Rate the buyer' : 'Write a review',
        ];
    }

    private function title(): string
    {
        return $this->forSeller
            ? 'Rate '.$this->subjectName
            : 'How was '.$this->subjectName.'?';
    }

    private function body(): string
    {
        return sprintf(
            'Order %s is complete. %s',
            $this->order->number,
            $this->forSeller
                ? 'Your rating of this buyer is private — only other sellers and MonaFind staff see it.'
                : 'Your review appears on the shop\'s page and helps other buyers choose.',
        );
    }

    private function url(): string
    {
        return $this->forSeller
            ? route('seller.orders.show', $this->order)
            : route('orders.show', $this->order);
    }
}
