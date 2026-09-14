<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Models\Refund;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'reference' => 'RF-'.strtoupper($this->faker->bothify('MF-########')).'-1',
            'status' => RefundStatus::Pending,
            'method' => RefundMethod::MobileMoney,
            'reason_code' => RefundReason::Dispute,
            'amount_ngwee' => Money::ofNgwee($this->faker->numberBetween(5_000, 200_000)),
            'destination_phone' => '260971234567',
            'destination_network' => 'mtn',
        ];
    }

    /**
     * A card refund waiting on a Finance human.
     */
    public function manual(): static
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Manual,
            'method' => RefundMethod::CardManual,
            'destination_phone' => null,
            'destination_network' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Completed,
            'completed_at' => now(),
            'sent_at' => now(),
        ]);
    }
}
