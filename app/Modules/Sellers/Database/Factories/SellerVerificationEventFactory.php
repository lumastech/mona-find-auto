<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Database\Factories;

use App\Models\User;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerVerificationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerVerificationEvent>
 */
class SellerVerificationEventFactory extends Factory
{
    protected $model = SellerVerificationEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory(),
            'from_status' => VerificationStatus::Submitted,
            'to_status' => VerificationStatus::UnderReview,
            'note' => fake()->sentence(),
            'actor_id' => User::factory(),
            'created_at' => now(),
        ];
    }

    public function transition(VerificationStatus $from, VerificationStatus $to): static
    {
        return $this->state(['from_status' => $from, 'to_status' => $to]);
    }
}
