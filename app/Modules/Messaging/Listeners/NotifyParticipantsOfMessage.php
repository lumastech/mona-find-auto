<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Listeners;

use App\Modules\Messaging\Events\MessagePosted;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThreadParticipant;
use App\Modules\Messaging\Notifications\NewMessageNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell everybody in the conversation except the person who wrote.
 *
 * Queued, because a buyer pressing Send should see their message appear
 * whether or not a mail server is answering — and because the notification it
 * dispatches is itself queued, so this only ever does a query and a fan-out.
 */
class NotifyParticipantsOfMessage implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(MessagePosted $event): void
    {
        $message = $event->message;

        $recipients = $message->thread
            ->participants()
            ->where('user_id', '!=', $message->user_id)
            ->with('user')
            ->get();

        foreach ($recipients as $participant) {
            $this->notify($participant, $message);
        }
    }

    private function notify(MessageThreadParticipant $participant, Message $message): void
    {
        $participant->user->notify(new NewMessageNotification($message));
    }
}
