<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Notifications;

use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your listing is live" — or "it is not, and here is why".
 *
 * One notification for both outcomes rather than two classes, because a
 * seller experiences them as the same event: they submitted something and
 * moderation has answered. Splitting them would mean two preference switches
 * for one question, and the one that matters is the rejection.
 *
 * The rejection reason is always included. A listing refused without one is a
 * seller guessing at what to change, and then submitting the same listing
 * again — which costs the moderator the same minute twice.
 */
class ListingModerated extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly Product $listing,
        private readonly ListingStatus $status,
        private readonly ?string $reason = null,
    ) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::ListingModerated;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title())
            ->line($this->body());

        if ($this->reason !== null && $this->reason !== '') {
            $message->line('Reason: '.$this->reason);
        }

        return $message->action($this->actionLabel(), $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::ListingModerated->value,
            'listing_id' => $this->listing->getKey(),
            'listing_name' => $this->listing->name,
            'status' => $this->status->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'reason' => $this->reason,
            'action_url' => $this->url(),
            'action_label' => $this->actionLabel(),
        ];
    }

    private function title(): string
    {
        return match ($this->status) {
            ListingStatus::Published => $this->listing->name.' is now live',
            ListingStatus::Rejected => $this->listing->name.' was not approved',
            default => $this->listing->name.' was taken down',
        };
    }

    private function body(): string
    {
        return match ($this->status) {
            ListingStatus::Published => 'Buyers can now find it in search and on your shopfront.',
            ListingStatus::Rejected => 'A moderator has refused this listing. Correct it and submit it again.',
            default => 'A moderator has removed this listing from the storefront.',
        };
    }

    private function actionLabel(): string
    {
        return $this->status === ListingStatus::Published ? 'View the listing' : 'Edit the listing';
    }

    private function url(): string
    {
        return $this->status === ListingStatus::Published
            ? route('listings.show', $this->listing)
            : route('seller.listings.edit', $this->listing);
    }
}
