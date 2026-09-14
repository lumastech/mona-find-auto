<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Database\Factories;

use App\Models\User;
use App\Modules\Shopping\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['user_id' => User::factory()];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->getKey()]);
    }
}
