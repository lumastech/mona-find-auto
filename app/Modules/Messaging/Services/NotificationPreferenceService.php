<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes one person's notification choices.
 *
 * The screen it feeds shows the truth rather than a wish: a channel staff
 * have switched off platform-wide is shown as unavailable rather than as a
 * switch that appears to be on and delivers nothing, and a mandatory event is
 * shown as fixed rather than as a switch that silently ignores the person.
 *
 * Writes are narrowed to what is actually offered — a posted preference for
 * a mandatory event, or for a channel the matrix does not allow, is dropped
 * rather than stored, so the table never accumulates rows the router would
 * not read.
 */
class NotificationPreferenceService
{
    public function __construct(private readonly NotificationMatrix $matrix) {}

    /**
     * The preference screen, grouped and ready to render.
     *
     * @return array<int, array{group: string, events: array<int, array{
     *     event: string,
     *     label: string,
     *     description: string,
     *     mandatory: bool,
     *     channels: array<int, array{channel: string, label: string, available: bool, enabled: bool, locked: bool}>
     * }>}>
     */
    public function screenFor(User $user): array
    {
        $muted = NotificationPreference::mutedKeysFor($user);
        $screen = [];

        foreach (NotificationEvent::grouped() as $group => $events) {
            $rows = [];

            foreach ($events as $event) {
                $allowed = $this->matrix->channelsFor($event);

                $rows[] = [
                    'event' => $event->value,
                    'label' => $event->label(),
                    'description' => $event->description(),
                    'mandatory' => $event->isMandatory(),
                    'channels' => array_map(
                        function (NotificationChannel $channel) use ($event, $allowed, $muted): array {
                            $available = in_array($channel, $allowed, true);

                            return [
                                'channel' => $channel->value,
                                'label' => $channel->label(),
                                'available' => $available,
                                'enabled' => $available && ! in_array(
                                    $event->value.'.'.$channel->value,
                                    $muted,
                                    true,
                                ),
                                /* Mandatory events and disallowed channels are not the person's to change. */
                                'locked' => $event->isMandatory() || ! $available,
                            ];
                        },
                        NotificationChannel::cases(),
                    ),
                ];
            }

            $screen[] = ['group' => $group, 'events' => $rows];
        }

        return $screen;
    }

    /**
     * Store a person's answers.
     *
     * Takes the whole screen at once — `['orders.placed' => ['sms' => false]]`
     * — because that is what the form posts and because a partial update
     * cannot tell "left alone" from "switched off".
     *
     * @param  array<string, mixed>  $submitted
     */
    public function update(User $user, array $submitted): void
    {
        $rows = [];
        $now = now();

        foreach ($submitted as $eventValue => $channels) {
            $event = NotificationEvent::tryFrom((string) $eventValue);

            /* A mandatory event has nothing to store: the router never looks. */
            if ($event === null || $event->isMandatory() || ! is_array($channels)) {
                continue;
            }

            $allowed = $this->matrix->channelsFor($event);

            foreach ($channels as $channelValue => $enabled) {
                $channel = NotificationChannel::tryFrom((string) $channelValue);

                if ($channel === null || ! in_array($channel, $allowed, true)) {
                    continue;
                }

                $rows[] = [
                    'user_id' => $user->getKey(),
                    'event' => $event->value,
                    'channel' => $channel->value,
                    'enabled' => (bool) $enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        /*
         * One upsert rather than a row at a time: the screen posts every
         * switch on it, which is some seventy rows, and seventy queries to
         * save a preference page is the kind of thing that makes a page feel
         * broken on a slow connection.
         */
        DB::transaction(function () use ($rows): void {
            NotificationPreference::query()->upsert(
                $rows,
                ['user_id', 'event', 'channel'],
                ['enabled', 'updated_at'],
            );
        });
    }

    /**
     * Whether this person would receive this event on this channel right now.
     *
     * The question the "you will not be told about this" hints on other
     * screens ask.
     */
    public function wouldReceive(User $user, NotificationEvent $event, NotificationChannel $channel): bool
    {
        if (! $this->matrix->allows($event, $channel)) {
            return false;
        }

        if ($event->isMandatory()) {
            return true;
        }

        return ! in_array(
            $event->value.'.'.$channel->value,
            NotificationPreference::mutedKeysFor($user),
            true,
        );
    }
}
