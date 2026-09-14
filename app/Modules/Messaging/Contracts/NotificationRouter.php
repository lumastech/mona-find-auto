<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;

/**
 * Decides which channels one notification actually goes out on.
 *
 * Behind an interface so a test can answer "database only" without seeding a
 * matrix, and so the trait every notification uses has one thing to call.
 */
interface NotificationRouter
{
    /**
     * The channels this event should use for this recipient, as Laravel
     * channel names.
     *
     * @return array<int, string>
     */
    public function driversFor(NotificationEvent $event, object $notifiable): array;

    /**
     * The same decision, as channel enums — what the preference and matrix
     * screens render.
     *
     * @return array<int, NotificationChannel>
     */
    public function channelsFor(NotificationEvent $event, object $notifiable): array;
}
