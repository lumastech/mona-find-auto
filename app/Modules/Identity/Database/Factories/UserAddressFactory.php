<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\UserAddress;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAddress>
 */
class UserAddressFactory extends Factory
{
    protected $model = UserAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /* The province is taken from the city, so the two can never disagree. */
        $city = City::factory()->create();

        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['Home', 'Workshop', 'Office', 'Mum']),
            'recipient_name' => fake()->name(),
            'recipient_phone' => UserFactory::zambianMobile(),
            'province_id' => $city->province_id,
            'city_id' => $city->id,
            'street' => fake()->streetName(),
            'plot_number' => (string) fake()->numberBetween(1, 9999),
            'is_default' => false,
        ];
    }

    /**
     * Put the address in an existing city rather than inventing a new one.
     */
    public function in(City $city): static
    {
        return $this->state(['province_id' => $city->province_id, 'city_id' => $city->id]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    /**
     * An address with a map pin dropped on it.
     */
    public function pinned(): static
    {
        return $this->state([
            'latitude' => fake()->randomFloat(6, -18.0, -8.2),
            'longitude' => fake()->randomFloat(6, 22.0, 33.7),
            'formatted_address' => fake()->address(),
        ]);
    }
}
