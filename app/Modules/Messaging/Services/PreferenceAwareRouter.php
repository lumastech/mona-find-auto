<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Contracts\NotificationRouter;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Models\NotificationPreference;

/**
 * Where one notification actually goes.
 *
 * Three filters, in this order, and the order is the whole design:
 *
 * 1. The admin matrix. A ceiling for the platform — if staff have switched
 *    SMS off for an event, nobody gets it by SMS, however they have set their
 *    preferences.
 * 2. The recipient's preferences, UNLESS the event is mandatory. A person may
 *    narrow what reaches them; they may not opt out of a verification code or
 *    a suspension notice, because an account nobody can get into and a
 *    support ticket are the two outcomes of letting them.
 * 3. Reachability. An email channel for an account with no address, or SMS to
 *    an unverified number, is a job that fails on the queue and a delivery
 *    report nobody can act on. Dropping the channel here turns that into a
 *    notification that simply arrives by the other means.
 *
 * The verified-phone rule in step three is deliberate and slightly
 * inconvenient: a seller who never finished phone verification gets email and
 * the bell and no texts, rather than texts to a number that may belong to
 * somebody else.
 */
class PreferenceAwareRouter implements NotificationRouter
{
    /**
     * A person's opt-outs, read once per request.
     *
     * A single order state change notifies two people and a dispute notifies
     * three; without this every one of them is a query, and a worker sending
     * a batch of reminders would do one per seller per channel.
     *
     * @var array<int, array<int, string>>
     */
    private array $mutedCache = [];

    public function __construct(private readonly NotificationMatrix $matrix) {}

    /**
     * @return array<int, string>
     */
    public function driversFor(NotificationEvent $event, object $notifiable): array
    {
        return array_map(
            static fn (NotificationChannel $channel): string => $channel->driver(),
            $this->channelsFor($event, $notifiable),
        );
    }

    /**
     * @return array<int, NotificationChannel>
     */
    public function channelsFor(NotificationEvent $event, object $notifiable): array
    {
        $channels = $this->matrix->channelsFor($event);

        if (! $event->isMandatory()) {
            $channels = $this->applyPreferences($event, $notifiable, $channels);
        }

        return array_values(array_filter(
            $channels,
            fn (NotificationChannel $channel): bool => $this->canReach($notifiable, $channel),
        ));
    }

    /**
     * What this person has switched off.
     *
     * Anything that is not a User — an on-demand notification routed straight
     * at a phone number, say — has no preferences to consult and keeps the
     * matrix's answer.
     *
     * @param  array<int, NotificationChannel>  $channels
     * @return array<int, NotificationChannel>
     */
    private function applyPreferences(NotificationEvent $event, object $notifiable, array $channels): array
    {
        if (! $notifiable instanceof User) {
            return $channels;
        }

        $muted = $this->mutedFor($notifiable);

        return array_values(array_filter(
            $channels,
            static fn (NotificationChannel $channel): bool => ! in_array(
                $event->value.'.'.$channel->value,
                $muted,
                true,
            ),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function mutedFor(User $user): array
    {
        $id = (int) $user->getKey();

        return $this->mutedCache[$id] ??= NotificationPreference::mutedKeysFor($user);
    }

    /**
     * Whether this recipient can actually be reached this way.
     */
    private function canReach(object $notifiable, NotificationChannel $channel): bool
    {
        return match ($channel) {
            /*
             * The bell needs somewhere to put the row. An on-demand
             * notification has no notifiable to hang one off, so it silently
             * has no in-app copy rather than throwing at send time.
             */
            NotificationChannel::Database => $notifiable instanceof User,

            NotificationChannel::Mail => filled($this->route($notifiable, 'mail') ?? ($notifiable->email ?? null)),

            NotificationChannel::Sms => $this->hasUsableNumber($notifiable),
        };
    }

    private function hasUsableNumber(object $notifiable): bool
    {
        $routed = $this->route($notifiable, 'sms');

        if (filled($routed)) {
            return true;
        }

        /*
         * An unverified number is not a number. It is what somebody typed
         * during registration and may belong to whoever had it last.
         */
        return $notifiable instanceof User
            && filled($notifiable->phone)
            && $notifiable->phone_verified_at !== null;
    }

    private function route(object $notifiable, string $channel): mixed
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        /*
         * A User routes `mail` to its own address by convention, which would
         * make every account look reachable by SMS too if the base trait
         * guessed. It does not — it looks for routeNotificationForSms — so
         * this is only ever an explicit answer.
         */
        return $notifiable->routeNotificationFor($channel);
    }
}
