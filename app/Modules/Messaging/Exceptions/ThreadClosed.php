<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use App\Modules\Messaging\Models\MessageThread;
use RuntimeException;

/**
 * The conversation is finished and cannot be added to.
 *
 * Carries a sentence a buyer can read, because this one does surface: an
 * order closing while somebody is typing is a real race, not a bug.
 */
class ThreadClosed extends RuntimeException
{
    public static function forThread(MessageThread $thread): self
    {
        return new self(sprintf(
            'This conversation about %s is closed and cannot take new messages.',
            $thread->subject_label,
        ));
    }
}
