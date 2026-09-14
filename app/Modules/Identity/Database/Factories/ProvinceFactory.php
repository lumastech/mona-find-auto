<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Province>
 */
class ProvinceFactory extends Factory
{
    protected $model = Province::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city().' Province';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'capital' => fake()->city(),
            'position' => fake()->numberBetween(1, 10),
        ];
    }
}
