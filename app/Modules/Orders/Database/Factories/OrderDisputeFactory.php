<?php

declare(strict_types=1);

namespace App\Modules\Orders\Database\Factories;

use App\Models\User;
use App\Modules\Orders\Enums\DisputeReason;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Enums\DisputeStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderDispute>
 */
class OrderDisputeFactory extends Factory
{
    protected $model = OrderDispute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'opened_by' => User::factory(),
            'reason' => $this->faker->randomElement(DisputeReason::cases()),
            'details' => $this->faker->sentence(12),
            'status' => DisputeStatus::Open,
        ];
    }

    public function underReview(): static
    {
        return $this->state(['status' => DisputeStatus::UnderReview]);
    }

    public function resolved(DisputeResolution $resolution = DisputeResolution::Release): static
    {
        return $this->state([
            'status' => DisputeStatus::Resolved,
            'resolution' => $resolution,
            'resolved_at' => now(),
            'resolution_note' => $this->faker->sentence(),
        ]);
    }
}
