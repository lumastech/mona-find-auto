<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Models\User;
use App\Modules\Messaging\Enums\ThreadRole;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessageThreadParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageThreadParticipant>
 */
class MessageThreadParticipantFactory extends Factory
{
    protected $model = MessageThreadParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_thread_id' => MessageThread::factory(),
            'user_id' => User::factory(),
            'role' => ThreadRole::Buyer,
            'last_read_at' => null,
        ];
    }

    public function seller(): self
    {
        return $this->state(fn (array $attributes): array => ['role' => ThreadRole::Seller]);
    }

    public function read(): self
    {
        return $this->state(fn (array $attributes): array => ['last_read_at' => now()]);
    }
}
