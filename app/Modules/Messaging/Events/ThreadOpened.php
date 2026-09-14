<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A conversation was started.
 *
 * Nothing listens yet. It is dispatched because the first message in a thread
 * fires MessagePosted a moment later and carries the notification, so an
 * empty thread is genuinely news to nobody — but "when did these two start
 * talking" is the kind of question a moderation view asks, and the event is
 * where that will hang.
 */
class ThreadOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(public MessageThread $thread) {}
}
