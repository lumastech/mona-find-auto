<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Support\ConversationName;
use App\Modules\Messaging\Support\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "Somebody wrote to you."
 *
 * The message itself is deliberately NOT reproduced in full anywhere outside
 * the app. A preview of a few words is enough to decide whether to open it,
 * and a conversation that may contain a plot number or a price does not
 * belong in full in an email trail or, worse, on a lock screen.
 *
 * Not on SMS by default either. "Somebody replied" is the one kind of news
 * here that is not worth a few ngwee and an interruption — the bell and the
 * email carry it, and a seller waiting on an answer is already looking.
 */
class NewMessageNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    /** Enough to recognise the conversation, not enough to have read it. */
    private const PREVIEW_LENGTH = 120;

    public function __construct(private readonly Message $message)
    {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::MessageReceived;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New message about '.$this->message->thread->subject_label)
            ->line($this->authorName().' wrote:')
            ->line('"'.$this->preview().'"')
            ->action('Open the conversation', route('threads.show', $this->message->thread))
            ->line('Reply on MonaFind so the conversation stays on the order it belongs to.');
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'MonaFind: %s replied about %s. Open the app to read it.',
            $this->authorName(),
            $this->message->thread->subject_label,
        ))->reference('messages:'.$this->message->thread->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::MessageReceived->value,
            'thread_id' => $this->message->message_thread_id,
            'message_id' => $this->message->getKey(),
            'title' => $this->authorName().' replied',
            'body' => $this->preview(),
            'subject_label' => $this->message->thread->subject_label,
            'action_url' => route('threads.show', $this->message->thread),
            'action_label' => 'Open the conversation',
        ];
    }

    private function authorName(): string
    {
        return ConversationName::forOrPlatform($this->message->author);
    }

    private function preview(): string
    {
        return Str::limit($this->message->body, self::PREVIEW_LENGTH);
    }
}
