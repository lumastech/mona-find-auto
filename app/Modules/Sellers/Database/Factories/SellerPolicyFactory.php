<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Database\Factories;

use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerPolicy>
 */
class SellerPolicyFactory extends Factory
{
    protected $model = SellerPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory(),
            'type' => fake()->randomElement(PolicyType::cases()),
            'version' => 1,
            'body' => '<p>'.fake()->paragraph(4).'</p>',
            'effective_from' => now(),
            'is_current' => true,
        ];
    }

    public function ofType(PolicyType $type): static
    {
        return $this->state(['type' => $type]);
    }

    /**
     * A version that has been replaced. Kept forever: an order records which
     * version its buyer accepted, and that text has to stay readable.
     */
    public function superseded(): static
    {
        return $this->state(['is_current' => false]);
    }

    /**
     * A version published today but not in force until later.
     */
    public function effectiveFrom(string $date): static
    {
        return $this->state(['effective_from' => $date]);
    }
}
