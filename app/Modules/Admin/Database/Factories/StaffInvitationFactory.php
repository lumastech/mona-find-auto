<?php

declare(strict_types=1);

namespace App\Modules\Admin\Database\Factories;

use App\Modules\Admin\Models\StaffInvitation;
use App\Support\Roles\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffInvitation>
 */
class StaffInvitationFactory extends Factory
{
    protected $model = StaffInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'role' => Role::Moderator->value,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state([
            'revoked_at' => now(),
            'revoked_reason' => 'Wrong address.',
        ]);
    }
}
