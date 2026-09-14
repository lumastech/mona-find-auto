<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Models\User;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event' => NotificationEvent::OrderStateChanged,
            'channel' => NotificationChannel::Sms,
            'enabled' => true,
        ];
    }

    /**
     * The only state that changes anything: a row exists precisely because
     * somebody switched something off.
     */
    public function muted(): self
    {
        return $this->state(fn (array $attributes): array => ['enabled' => false]);
    }
}
