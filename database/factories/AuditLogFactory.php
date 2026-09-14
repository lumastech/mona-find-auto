<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_type' => null,
            'actor_id' => null,
            'actor_label' => 'System',
            'action' => fake()->randomElement([
                'seller.verified',
                'listing.moderated',
                'order.refunded',
                'payout.released',
                'setting.updated',
            ]),
            'subject_type' => null,
            'subject_id' => null,
            'before' => null,
            'after' => null,
            'reason' => fake()->optional()->sentence(),
            'context' => ['channel' => 'console'],
            'created_at' => now(),
        ];
    }

    /**
     * Attribute the entry to a staff member rather than the platform.
     */
    public function byActor(?Model $actor = null): self
    {
        $actor ??= User::factory()->create();

        return $this->state(fn (): array => [
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'actor_label' => $actor->getAttribute('name') ?? $actor->getMorphClass(),
            'context' => ['channel' => 'http', 'ip' => fake()->ipv4()],
        ]);
    }

    public function forSubject(Model $subject): self
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function withChange(array $before, array $after): self
    {
        return $this->state(fn (): array => [
            'before' => $before,
            'after' => $after,
        ]);
    }
}
