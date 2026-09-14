<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;

/**
 * The platform's event→channel grid, as staff have set it.
 *
 * This is a ceiling, not a floor. It says the most a given event may use; a
 * person's own preferences can narrow it further and can never widen it. So
 * switching SMS off for "order progress" here stops every one of those texts
 * platform-wide, immediately, without a deployment — which is the reason the
 * grid exists at all, because SMS costs money per message and the bill is
 * discovered after the fact.
 *
 * Stored as one Array setting rather than a table: it is a single small
 * document read on every notification, `settings()` already caches it, and
 * writing it through `settings()->set()` gets the audit row for free.
 *
 * An event missing from the stored grid falls back to its own defaults rather
 * than to nothing. That way adding a case to the catalogue works on the next
 * deploy instead of going silently undelivered until somebody notices the
 * matrix screen has a new empty row.
 */
class NotificationMatrix
{
    public const SETTING_KEY = 'notifications.matrix';

    /**
     * The channels staff allow for this event.
     *
     * @return array<int, NotificationChannel>
     */
    public function channelsFor(NotificationEvent $event): array
    {
        $stored = $this->stored();

        if (! array_key_exists($event->value, $stored)) {
            return $event->defaultChannels();
        }

        $configured = $stored[$event->value];

        return is_array($configured)
            ? NotificationChannel::fromValues($configured)
            : $event->defaultChannels();
    }

    public function allows(NotificationEvent $event, NotificationChannel $channel): bool
    {
        return in_array($channel, $this->channelsFor($event), true);
    }

    /**
     * The whole grid, every event present, for the admin screen and the API.
     *
     * @return array<string, array<int, string>>
     */
    public function toArray(): array
    {
        $matrix = [];

        foreach (NotificationEvent::cases() as $event) {
            $matrix[$event->value] = array_map(
                static fn (NotificationChannel $channel): string => $channel->value,
                $this->channelsFor($event),
            );
        }

        return $matrix;
    }

    /**
     * Replace the grid.
     *
     * Rebuilt from the catalogue rather than from what was posted, so an
     * event the form did not know about keeps its current setting instead of
     * being cleared by an older browser tab.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, array<int, string>>
     */
    public function replace(array $submitted, ?User $actor = null, ?string $reason = null): array
    {
        $before = $this->toArray();
        $matrix = [];

        foreach (NotificationEvent::cases() as $event) {
            $channels = array_key_exists($event->value, $submitted) && is_array($submitted[$event->value])
                ? NotificationChannel::fromValues($submitted[$event->value])
                : $this->channelsFor($event);

            $matrix[$event->value] = array_map(
                static fn (NotificationChannel $channel): string => $channel->value,
                $channels,
            );
        }

        /*
         * settings()->set() flushes the cache and writes the audit row; the
         * extra audit here records the shape of the change rather than the
         * fact of it, because "who turned SMS off for payouts" is the
         * question somebody asks a month later.
         */
        settings()->set(self::SETTING_KEY, $matrix, $actor, $reason);

        audit($actor, 'notifications.matrix_updated', null, $before, $matrix, $reason);

        return $matrix;
    }

    /**
     * The grid as a fresh install has it.
     *
     * @return array<string, array<int, string>>
     */
    public static function defaults(): array
    {
        $matrix = [];

        foreach (NotificationEvent::cases() as $event) {
            $matrix[$event->value] = array_map(
                static fn (NotificationChannel $channel): string => $channel->value,
                $event->defaultChannels(),
            );
        }

        return $matrix;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $stored = settings(self::SETTING_KEY, []);

        return is_array($stored) ? $stored : [];
    }
}
