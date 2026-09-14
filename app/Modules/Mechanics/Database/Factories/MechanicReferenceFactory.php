<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Database\Factories;

use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicReference;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MechanicReference>
 */
class MechanicReferenceFactory extends Factory
{
    protected $model = MechanicReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mechanic_profile_id' => MechanicProfile::factory(),
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['Former employer', 'Workshop owner', 'Long-standing customer']),
            'phone' => UserFactory::zambianMobile(),
            'email' => fake()->safeEmail(),
            'note' => fake()->sentence(),
            'position' => 0,
        ];
    }
}
