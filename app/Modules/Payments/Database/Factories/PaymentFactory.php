<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Integrations\Payments\Data\PaymentStatus;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Payments\Enums\PaymentChannel;
use App\Modules\Payments\Enums\PaymentSource;
use App\Modules\Payments\Models\Payment;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_group_id' => OrderGroup::factory(),
            'user_id' => null,
            'reference' => 'MFA-'.strtoupper($this->faker->bothify('??########')).'-1',
            'attempt' => 1,
            'status' => PaymentStatus::Pending,
            'channel' => PaymentChannel::MobileMoney,
            'bearer' => 'merchant',
            'amount_ngwee' => Money::ofNgwee($this->faker->numberBetween(10_000, 500_000)),
            'source' => PaymentSource::Initiate,
            'observed_at' => now(),
        ];
    }

    /**
     * A confirmed collection.
     *
     * Carries a `raw` payload shaped like Lenco's, because that is where a
     * refund reads the buyer's wallet from — a successful payment with no raw
     * body cannot be refunded and would make the refund tests pass for the
     * wrong reason.
     */
    public function successful(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Successful,
            'source' => PaymentSource::Webhook,
            'fee_ngwee' => Money::ofNgwee(1_500),
            'lenco_id' => 'col_'.$this->faker->uuid(),
            'raw' => [
                'type' => 'mobile-money',
                'bearer' => 'merchant',
                'mobileMoneyDetails' => [
                    'country' => 'zm',
                    'phone' => '260971234567',
                    'operator' => 'mtn',
                ],
            ],
        ]);
    }

    public function failed(string $reason = 'Insufficient funds'): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed,
            'source' => PaymentSource::Verify,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * A card payment, which cannot be refunded automatically.
     */
    public function card(): static
    {
        return $this->state(fn (): array => [
            'channel' => PaymentChannel::Card,
            'raw' => ['type' => 'card', 'bearer' => 'merchant', 'cardDetails' => ['last4' => '4242']],
        ]);
    }

    /**
     * Pending long enough for the stuck-payment poller to find it.
     */
    public function stuck(int $minutes = 30): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Pending,
            'observed_at' => now()->subMinutes($minutes),
        ]);
    }
}
