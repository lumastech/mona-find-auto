<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Ratings\Models\Rating;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "Somebody has reviewed you."
 *
 * Sent to the party a rating is ABOUT, which is not always a person: a review
 * of a shop is about the business, so it reaches the account that runs it.
 *
 * Only for the directions the ratee is allowed to see. A buyer is
 * deliberately never shown a seller's rating of them — that is the rule that
 * stops a bad review being traded for a good rating — and notifying them that
 * one exists would defeat it as thoroughly as showing them the stars. So this
 * is dispatched by a listener that checks the direction first, and this class
 * assumes that check has happened.
 *
 * The body is the review as PUBLISHED, redacted and all. There is no version
 * of it anywhere that is not.
 */
class RatingReceived extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    private const PREVIEW_LENGTH = 140;

    public function __construct(private readonly Rating $rating) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::RatingReceived;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title())
            ->line($this->title());

        if (filled($this->rating->body)) {
            $message->line('"'.$this->preview().'"');
        }

        return $message
            ->action('Read it and reply', $this->url())
            ->line('A short, calm reply to a poor review is read by every buyer who sees it afterwards.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::RatingReceived->value,
            'rating_id' => $this->rating->getKey(),
            'stars' => $this->rating->stars,
            'direction' => $this->rating->direction->value,
            'title' => $this->title(),
            'body' => $this->preview(),
            'action_url' => $this->url(),
            'action_label' => 'Read it and reply',
        ];
    }

    private function title(): string
    {
        return sprintf('You have a new %d-star review', $this->rating->stars);
    }

    private function preview(): string
    {
        return Str::limit((string) $this->rating->body, self::PREVIEW_LENGTH);
    }

    private function url(): string
    {
        return route('seller.ratings.index');
    }
}
