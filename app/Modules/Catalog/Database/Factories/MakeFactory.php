<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Models\Make;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Make>
 */
class MakeFactory extends Factory
{
    protected $model = Make::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'country' => fake()->country(),
            'is_popular' => false,
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function popular(): static
    {
        return $this->state(['is_popular' => true]);
    }

    public function retired(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A real marque, for tests that read better with one.
     */
    public function named(string $name): static
    {
        return $this->state(['name' => $name, 'slug' => Str::slug($name)]);
    }
}
