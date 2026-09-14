<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Policies;

use App\Models\User;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Orders\Models\Order;
use App\Support\Roles\Role;

/**
 * Who may read and write a conversation.
 *
 * Membership is the rule and there is only one exception to it.
 *
 * A participant may read the thread and write in it. Nobody else may do
 * either — not another buyer, not another shop, not a member of staff going
 * about their day. Private conversations between two traders are not
 * platform reading material, and a console that could open any of them would
 * make every buyer write as though it could.
 *
 * The exception is a REPORTED DISPUTE. When a buyer raises a dispute on an
 * order, staff have to decide it, and deciding it on the parties' accounts of
 * a conversation neither can produce is not deciding it at all. So a
 * moderator may read — never write — the thread of an order that has a
 * dispute row against it.
 *
 * Two things that exception is deliberately narrow about. It is the ORDER's
 * thread, not every thread those two people have ever had: a dispute about a
 * gearbox does not open the conversation about a windscreen. And it is read
 * only: staff who could post as a participant could manufacture the evidence
 * they are weighing.
 */
class MessageThreadPolicy
{
    public function view(User $user, MessageThread $thread): bool
    {
        return $thread->hasParticipant($user) || $this->staffMayReadForDispute($user, $thread);
    }

    /**
     * Write into it. Participants only, and only while it is open.
     *
     * Staff are excluded even on a disputed order: their say goes in the
     * dispute's resolution note, where it is auditable and both sides see the
     * same thing.
     */
    public function reply(User $user, MessageThread $thread): bool
    {
        return $thread->hasParticipant($user) && ! $thread->isClosed();
    }

    /**
     * Mark as read. The people who can read it, minus the staff exception —
     * a moderator looking at a disputed thread is not a participant and has
     * no read position to move.
     */
    public function markRead(User $user, MessageThread $thread): bool
    {
        return $thread->hasParticipant($user);
    }

    /**
     * Whether staff may read this thread because its order is disputed.
     *
     * The dispute row is what grants it, not the order's status: a moderator
     * may move an order out of Disputed while still deciding, and the
     * evidence should not disappear the moment they do.
     */
    private function staffMayReadForDispute(User $user, MessageThread $thread): bool
    {
        if (! $user->hasAnyRole(Role::staffConsole())) {
            return false;
        }

        if ($thread->subject_type !== (new Order)->getMorphClass()) {
            return false;
        }

        return Order::query()
            ->whereKey($thread->subject_id)
            ->whereHas('disputes')
            ->exists();
    }
}
