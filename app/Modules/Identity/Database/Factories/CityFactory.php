<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'province_id' => Province::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            /* Somewhere inside Zambia's bounding box. */
            'latitude' => fake()->randomFloat(6, -18.0, -8.2),
            'longitude' => fake()->randomFloat(6, 22.0, 33.7),
            'is_major' => false,
        ];
    }

    public function major(): static
    {
        return $this->state(['is_major' => true]);
    }
}
