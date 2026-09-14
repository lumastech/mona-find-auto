<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Privacy;

use App\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThreadParticipant;
use App\Modules\Messaging\Models\NotificationPreference;
use App\Modules\Privacy\Contracts\PersonalDataSource;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;

/**
 * What a person wrote, and how they asked to be contacted.
 *
 * ## Messages are redacted, not deleted
 *
 * This is the one place in the module where erasure leaves a row standing,
 * and the reason is the other person in the conversation. A thread is shared:
 * deleting one participant's messages would rewrite a seller's record of an
 * exchange they were party to, leaving replies answering questions that no
 * longer appear to have been asked. That is not erasure, it is falsification
 * of somebody else's records.
 *
 * So the body becomes "[redacted]" and the author becomes an erased account.
 * The seller can still see that a conversation happened and when; they cannot
 * see who or what. The Act asks that the data subject be unidentifiable, and
 * after this they are.
 *
 * The participant rows go, which removes the thread from the erased account's
 * own inbox.
 */
class MessagingPersonalData implements PersonalDataSource
{
    public function key(): string
    {
        return 'messages';
    }

    /**
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array
    {
        return [
            PersonalDataSection::make(
                'Your messages',
                Message::query()
                    ->where('user_id', $user->getKey())
                    ->with('thread:id,subject_label')
                    ->latest('id')
                    ->limit(2000)
                    ->get()
                    ->map(static fn (Message $message): array => [
                        'Conversation' => $message->thread->subject_label,
                        'What you wrote' => $message->body,
                        'Sent on' => $message->created_at?->toDateTimeString(),
                    ])->all(),
                'Messages you have sent, most recent first. Limited to the last 2,000.',
            ),

            PersonalDataSection::make(
                'Your notification settings',
                NotificationPreference::query()
                    ->where('user_id', $user->getKey())
                    ->get()
                    ->map(static fn (NotificationPreference $preference): array => [
                        'Notification' => $preference->event->label(),
                        'Channel' => $preference->channel->value,
                        'Turned on' => $preference->enabled,
                    ])->all(),
                'Which notifications you have asked for, and how.',
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array
    {
        /*
         * Redacted in place. The other side of every one of these
         * conversations is a seller whose own record must stay coherent —
         * see the class docblock.
         */
        $messages = Message::query()
            ->where('user_id', $user->getKey())
            ->update([
                'body' => $anonymiser->text(),
                'updated_at' => now(),
            ]);

        return [
            'messages' => $messages,
            'message_thread_participants' => MessageThreadParticipant::query()
                ->where('user_id', $user->getKey())
                ->delete(),
            'notification_preferences' => NotificationPreference::query()
                ->where('user_id', $user->getKey())
                ->delete(),
        ];
    }
}
