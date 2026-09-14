<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\AccountRegistered;
use App\Modules\Identity\Notifications\WelcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * One welcome, however the account was created.
 *
 * Hung off the event rather than written into RegisterUser because the same
 * account can arrive through the web form, the JSON API or a Google sign-in,
 * and a welcome that only the web form sends is a welcome most people do not
 * get.
 */
class SendWelcomeNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(AccountRegistered $event): void
    {
        $event->user->notify(new WelcomeNotification($event->user));
    }
}
