<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Database\Factories;

use App\Modules\Mechanics\Models\MechanicSpeciality;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MechanicSpeciality>
 */
class MechanicSpecialityFactory extends Factory
{
    protected $model = MechanicSpeciality::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'position' => fake()->numberBetween(0, 50),
            'is_active' => true,
        ];
    }

    /**
     * Retired: still on the profiles that claim it, no longer choosable.
     */
    public function retired(): static
    {
        return $this->state(['is_active' => false]);
    }
}
