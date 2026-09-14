<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody wrote in a thread.
 *
 * Carries the message rather than the thread, because the listener needs to
 * know who wrote it — the one person in the conversation who must NOT be
 * notified is the author.
 */
class MessagePosted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}
}
