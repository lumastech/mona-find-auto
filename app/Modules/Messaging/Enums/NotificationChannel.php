<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

use App\Modules\Messaging\Channels\SmsChannel;

/**
 * The three ways MonaFind reaches a person.
 *
 * The order matters to the admin matrix screen and to nothing else; the cases
 * are deliberately few, because every channel added here is a channel every
 * notification in the platform has to have copy for.
 *
 * `driver()` is the string Laravel's notification manager understands. Keeping
 * the mapping here means the rest of the platform talks about channels in
 * terms of this enum, and a channel class moving is a one-line change.
 */
enum NotificationChannel: string
{
    /** The in-app bell. Free, permanent, and read when the person next signs in. */
    case Database = 'database';

    /** Read in the evening, if at all — but it is where a receipt belongs. */
    case Mail = 'mail';

    /** The channel that actually reaches a Zambian seller during a working day, and the only one that costs money per message. */
    case Sms = 'sms';

    /**
     * The channel name Laravel routes on.
     */
    public function driver(): string
    {
        return match ($this) {
            self::Database => 'database',
            self::Mail => 'mail',
            self::Sms => SmsChannel::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Database => 'In-app',
            self::Mail => 'Email',
            self::Sms => 'SMS',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Database => 'Appears in the notification bell.',
            self::Mail => 'Sent to the account email address.',
            self::Sms => 'Sent to the verified mobile number. Costs money per message.',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }

    /**
     * The channels named by a stored list, ignoring anything unrecognised —
     * a matrix row written before a channel was retired must not blow up the
     * request that reads it.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<int, self>
     */
    public static function fromValues(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): ?self => is_string($value) ? self::tryFrom($value) : null,
            $values,
        )));
    }
}
