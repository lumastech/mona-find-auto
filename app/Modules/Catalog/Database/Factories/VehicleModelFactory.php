<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Enums\BodyType;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleModel>
 */
class VehicleModelFactory extends Factory
{
    protected $model = VehicleModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word());
        $start = fake()->numberBetween(1990, 2015);

        return [
            'make_id' => Make::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'body_type' => fake()->randomElement(BodyType::cases()),
            'production_start_year' => $start,
            'production_end_year' => fake()->boolean(60) ? $start + fake()->numberBetween(4, 10) : null,
            'is_popular' => false,
            'is_active' => true,
        ];
    }

    public function of(Make $make): static
    {
        return $this->state(['make_id' => $make->getKey()]);
    }

    /**
     * Still being built, so any recent year is a plausible fitment.
     */
    public function inProduction(): static
    {
        return $this->state(['production_end_year' => null]);
    }
}
