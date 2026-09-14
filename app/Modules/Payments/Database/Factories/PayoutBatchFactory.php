<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Models\User;
use App\Modules\Payments\Enums\PayoutBatchStatus;
use App\Modules\Payments\Models\PayoutBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutBatch>
 */
class PayoutBatchFactory extends Factory
{
    protected $model = PayoutBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'PB-'.now()->format('Ymd').'-'.$this->faker->unique()->numberBetween(1, 9999),
            'status' => PayoutBatchStatus::AwaitingApproval,
            'line_count' => 0,
            'total_ngwee' => 0,
            'scheduled_for' => now(),
        ];
    }

    public function approved(?User $approver = null): static
    {
        return $this->state(fn (): array => [
            'status' => PayoutBatchStatus::Approved,
            'approved_by' => $approver?->getKey() ?? User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => PayoutBatchStatus::Processing,
            'started_at' => now(),
        ]);
    }
}
