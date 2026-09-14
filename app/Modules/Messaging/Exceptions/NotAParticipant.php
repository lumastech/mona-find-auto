<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use App\Models\User;
use App\Modules\Messaging\Models\MessageThread;
use RuntimeException;

/**
 * Somebody tried to write in a conversation they are not part of.
 *
 * The policy stops this at the door, so reaching here means a service was
 * called directly — a job, a console command, a controller that forgot. It is
 * an exception rather than a silent no-op because a message the platform
 * quietly discards is worse than an error nobody sees.
 */
class NotAParticipant extends RuntimeException
{
    public static function forThread(MessageThread $thread, User $user): self
    {
        return new self(sprintf(
            'User %d is not a participant in thread %d.',
            $user->getKey(),
            $thread->getKey(),
        ));
    }
}
