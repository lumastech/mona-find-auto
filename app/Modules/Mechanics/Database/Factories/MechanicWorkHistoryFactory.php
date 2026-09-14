<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Database\Factories;

use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicWorkHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MechanicWorkHistory>
 */
class MechanicWorkHistoryFactory extends Factory
{
    protected $model = MechanicWorkHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-15 years', '-3 years');

        return [
            'mechanic_profile_id' => MechanicProfile::factory(),
            'employer' => fake()->company().' Motors',
            'role' => fake()->randomElement(['Mechanic', 'Senior mechanic', 'Workshop foreman', 'Apprentice']),
            'description' => fake()->sentence(12),
            'started_on' => $started,
            'ended_on' => fake()->dateTimeBetween($started, '-1 month'),
            'is_current' => false,
            'position' => 0,
        ];
    }

    public function current(): static
    {
        return $this->state(['is_current' => true, 'ended_on' => null]);
    }
}
