<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Support\Content\ScreenFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_thread_id' => MessageThread::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(12),
            'screen_flags' => [],
        ];
    }

    /**
     * A message the screen took a phone number out of.
     */
    public function redacted(): self
    {
        return $this->state(fn (array $attributes): array => [
            'body' => 'Call me on [removed] and I will bring it round.',
            'screen_flags' => [ScreenFlag::PhoneNumber->value],
        ]);
    }

    /**
     * A line the platform wrote, with no author.
     */
    public function fromPlatform(): self
    {
        return $this->state(fn (array $attributes): array => ['user_id' => null]);
    }
}
