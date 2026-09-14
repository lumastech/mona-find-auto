<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Models\User;
use App\Modules\Messaging\Database\Factories\NotificationPreferenceFactory;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's decision about one event on one channel.
 *
 * Only stored where it differs from the default — see the migration.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationEvent $event
 * @property NotificationChannel $channel
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'event', 'channel', 'enabled'])]
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * The channels this person has switched OFF, as "event.channel" keys.
     *
     * One query answers the whole preference question for a person, which is
     * what the router wants — it is called on every notification, and a
     * lookup per channel would be three queries to send one message.
     *
     * @return array<int, string>
     */
    public static function mutedKeysFor(User $user): array
    {
        return static::query()
            ->forUser($user)
            ->where('enabled', false)
            ->get(['event', 'channel'])
            ->map(static fn (self $preference): string => $preference->key())
            ->all();
    }

    public function key(): string
    {
        return $this->event->value.'.'.$this->channel->value;
    }
}
