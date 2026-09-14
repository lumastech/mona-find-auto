<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Modules\Payments\Enums\PayoutLineStatus;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Models\PayoutLine;
use App\Modules\Sellers\Enums\PayoutMethod;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutLine>
 */
class PayoutLineFactory extends Factory
{
    protected $model = PayoutLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payout_batch_id' => PayoutBatch::factory(),
            'seller_id' => Seller::factory(),
            'reference' => 'PO-'.$this->faker->unique()->numberBetween(1, 99999).'-1',
            'status' => PayoutLineStatus::Pending,
            'amount_ngwee' => Money::ofNgwee($this->faker->numberBetween(10_000, 800_000)),
            'method' => PayoutMethod::MobileMoney,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PayoutLineStatus::Paid,
            'settled_at' => now(),
            'sent_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Account closed'): static
    {
        return $this->state(fn (): array => [
            'status' => PayoutLineStatus::Failed,
            'failure_reason' => $reason,
            'failed_at' => now(),
        ]);
    }
}
