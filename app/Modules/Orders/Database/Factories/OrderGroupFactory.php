<?php

declare(strict_types=1);

namespace App\Modules\Orders\Database\Factories;

use App\Models\User;
use App\Modules\Orders\Enums\OrderGroupStatus;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Modules\Orders\Models\OrderGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderGroup>
 */
class OrderGroupFactory extends Factory
{
    protected $model = OrderGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $items = $this->faker->numberBetween(5_000, 500_000);

        return [
            'user_id' => User::factory(),
            'status' => OrderGroupStatus::PendingPayment,
            'payment_method' => $this->faker->randomElement(PaymentMethod::cases()),
            'items_total_ngwee' => $items,
            'delivery_total_ngwee' => 0,
            'total_ngwee' => $items,
            'placed_at' => now(),
        ];
    }

    public function forBuyer(User $user): static
    {
        return $this->state(['user_id' => $user->getKey()]);
    }

    public function paid(): static
    {
        return $this->state([
            'status' => OrderGroupStatus::Paid,
            'paid_at' => now(),
            'payment_attempts' => 1,
        ]);
    }
}
