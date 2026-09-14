<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Enums\ThreadRole;
use App\Modules\Messaging\Events\MessagePosted;
use App\Modules\Messaging\Events\ThreadOpened;
use App\Modules\Messaging\Exceptions\NotAParticipant;
use App\Modules\Messaging\Exceptions\ThreadClosed;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessageThreadParticipant;
use App\Modules\Messaging\Support\ThreadParties;
use App\Support\Content\ContentScreen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Opening threads, posting into them, and marking them read.
 *
 * Three things are worth knowing about this class.
 *
 * **Opening is idempotent.** `openFor()` is safe to call on every page load
 * of a listing, an order or a quote; a buyer pressing "Contact seller" four
 * times gets one conversation. The guarantee is the unique `dedupe_key`, not
 * the lookup — two simultaneous presses both find nothing and both insert,
 * and the loser of that race reads the winner's row rather than failing.
 *
 * **The screen is applied per thread, not per platform.** Whether contact
 * details survive depends on whether the thread's subject has been paid for,
 * which the subject's resolver answers. Before payment a phone number is
 * removed and the sender is told; after it, nothing is touched. And a flagged
 * message is delivered either way — holding a conversation for moderation
 * would break the conversation, which is not a trade worth making for a word
 * list.
 *
 * **Posting stamps the poster as read.** Otherwise answering a message leaves
 * it unread for the person who just answered it, and the bell becomes a
 * number nobody trusts.
 */
class ThreadService
{
    public function __construct(
        private readonly ThreadSubjectRegistry $subjects,
        private readonly ContentScreen $screen,
    ) {}

    /**
     * The thread for a subject whose participants are known from the subject
     * itself — an order, a quote request.
     */
    public function openFor(Model $subject): MessageThread
    {
        $resolver = $this->subjects->for($subject);

        return $this->openWith($subject, $resolver->parties($subject));
    }

    /**
     * The thread for a subject plus the people in it.
     *
     * The form a listing needs: a listing belongs to a shop but not to any
     * particular buyer, so which buyer is asking has to be supplied.
     */
    public function openWith(Model $subject, ThreadParties $parties): MessageThread
    {
        $resolver = $this->subjects->for($subject);
        $key = MessageThread::dedupeKeyFor($subject, $parties->userIds());

        $existing = MessageThread::query()->where('dedupe_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $thread = DB::transaction(function () use ($subject, $parties, $resolver, $key): MessageThread {
                $thread = MessageThread::query()->create([
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'dedupe_key' => $key,
                    'seller_id' => $parties->seller?->getKey(),
                    'subject_label' => $resolver->label($subject),
                ]);

                foreach ($parties->participants as $participant) {
                    MessageThreadParticipant::query()->create([
                        'message_thread_id' => $thread->getKey(),
                        'user_id' => $participant['user']->getKey(),
                        'role' => $participant['role'],
                    ]);
                }

                return $thread;
            });
        } catch (UniqueConstraintViolationException) {
            /*
             * Two presses of "Contact seller" landed together. The unique
             * index — not the lookup above — is what makes one conversation
             * true, and the loser of the race wants the winner's row rather
             * than an error page.
             */
            /** @var MessageThread */
            return MessageThread::query()->where('dedupe_key', $key)->firstOrFail();
        }

        ThreadOpened::dispatch($thread);

        return $thread->load('participants');
    }

    /**
     * Write a message into a thread.
     *
     * @param  array<int, UploadedFile>  $attachments
     *
     * @throws NotAParticipant when the author is not in the conversation.
     * @throws ThreadClosed when the conversation is finished.
     */
    public function post(
        MessageThread $thread,
        User $author,
        string $body,
        array $attachments = [],
    ): Message {
        if (! $thread->hasParticipant($author)) {
            throw NotAParticipant::forThread($thread, $author);
        }

        if ($thread->isClosed()) {
            throw ThreadClosed::forThread($thread);
        }

        /*
         * Screened only while the thread's money has not moved. After
         * payment the two sides are entitled to each other's details, and a
         * "[removed]" between a buyer and the shop holding their part is the
         * platform getting in the way of the thing it was paid to enable.
         */
        $screened = $thread->allowsContactDetails()
            ? null
            : $this->screen->screen($body);

        $text = trim($screened === null ? $body : (string) $screened->text);

        $message = DB::transaction(function () use ($thread, $author, $text, $screened, $attachments): Message {
            $message = Message::query()->create([
                'message_thread_id' => $thread->getKey(),
                'user_id' => $author->getKey(),
                'body' => $text,
                'screen_flags' => $screened?->flagValues() ?? [],
            ]);

            foreach ($attachments as $attachment) {
                $message->addMedia($attachment)->toMediaCollection(Message::ATTACHMENTS_COLLECTION);
            }

            $thread->forceFill([
                'messages_count' => $thread->messages_count + 1,
                'last_message_at' => $message->created_at,
            ])->save();

            /* Answering something is reading it. */
            $thread->participants()
                ->where('user_id', $author->getKey())
                ->update(['last_read_at' => $message->created_at]);

            return $message;
        });

        MessagePosted::dispatch($message);

        return $message;
    }

    /**
     * Mark everything in a thread as seen by this person.
     *
     * Silently does nothing for somebody who is not in the thread — a
     * moderator opening a disputed conversation is reading it, not joining
     * it, and should not acquire a participant row by looking.
     */
    public function markRead(MessageThread $thread, User $user): void
    {
        $thread->participants()
            ->where('user_id', $user->getKey())
            ->update(['last_read_at' => now()]);
    }

    /**
     * How many of this person's conversations have something new in them.
     *
     * Threads, not messages: "3" on the bell means three conversations to
     * open, which is the number a person can act on. Counting messages would
     * make one chatty seller look like an emergency.
     */
    public function unreadCountFor(User $user): int
    {
        return MessageThreadParticipant::query()
            ->join('message_threads', 'message_threads.id', '=', 'message_thread_participants.message_thread_id')
            ->where('message_thread_participants.user_id', $user->getKey())
            ->whereNotNull('message_threads.last_message_at')
            ->where(static fn ($query) => $query
                ->whereNull('message_thread_participants.last_read_at')
                ->orWhereColumn(
                    'message_threads.last_message_at',
                    '>',
                    'message_thread_participants.last_read_at',
                ))
            ->count();
    }

    /**
     * Close a thread, so it can be read but not added to.
     */
    public function close(MessageThread $thread): void
    {
        if ($thread->isClosed()) {
            return;
        }

        $thread->forceFill(['closed_at' => now()])->save();
    }

    /**
     * The role this person holds in a thread, or null when they hold none.
     */
    public function roleFor(MessageThread $thread, User $user): ?ThreadRole
    {
        return $thread->participantFor($user)?->role;
    }
}
