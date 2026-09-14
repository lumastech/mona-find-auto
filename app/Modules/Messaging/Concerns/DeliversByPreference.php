<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Concerns;

use App\Modules\Messaging\Contracts\NotificationRouter;
use App\Modules\Messaging\Enums\NotificationChannel;
use Illuminate\Queue\Attributes\Tries;

/**
 * The `via()`, the queue and the retry policy every platform notification
 * shares.
 *
 * A notification says what it is (PlatformNotification::notificationEvent())
 * and writes the copy for each channel; it never decides where it goes. That
 * decision is one rule — matrix, then preferences, then reachability — and it
 * lives in the router so that turning a channel off in the staff console
 * turns it off everywhere rather than in the notifications somebody
 * remembered to update.
 *
 * The router is resolved out of the container per send rather than injected,
 * because a notification is serialised onto the queue and a router with a
 * settings repository inside it has no business travelling with it.
 */
/**
 * Five attempts over about thirteen minutes.
 *
 * Sized for the failure that actually happens: an SMTP host or an SMS gateway
 * that is briefly unreachable. Past that the news is usually stale — the
 * seller has opened the app, the buyer has rung — and a queue clogged with
 * day-old notifications delays the ones that matter.
 *
 * An ATTRIBUTE rather than a `$tries` property, so that a notification with a
 * shorter life (OtpNotification) can say so with its own attribute. Two
 * traits-and-classes both declaring the same public property is a fatal
 * composition error in PHP, which is the kind of thing a shared trait must
 * not make possible.
 */
#[Tries(5)]
trait DeliversByPreference
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationRouter::class)->driversFor($this->notificationEvent(), $notifiable);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 180, 600];
    }

    /**
     * Every channel goes onto the notifications queue.
     *
     * Its own queue rather than the default, so that a flood of stock
     * reminders cannot sit in front of a payment webhook — Horizon gives the
     * two different workers.
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        $queue = (string) config('monafind.queues.notifications', 'notifications');

        $queues = [];

        foreach (NotificationChannel::cases() as $channel) {
            $queues[$channel->driver()] = $queue;
        }

        return $queues;
    }
}
