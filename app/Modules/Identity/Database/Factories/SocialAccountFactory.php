<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Models\User;
use App\Modules\Identity\Enums\SocialProvider;
use App\Modules\Identity\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => SocialProvider::Google->value,
            'provider_id' => (string) fake()->unique()->numerify('##################'),
            'email' => fake()->unique()->safeEmail(),
            'nickname' => fake()->userName(),
            'avatar_url' => fake()->imageUrl(),
        ];
    }

    public function facebook(): static
    {
        return $this->state(['provider' => SocialProvider::Facebook->value]);
    }
}
