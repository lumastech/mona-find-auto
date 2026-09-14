<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Database\Factories;

use App\Modules\Ledger\Enums\CommissionType;
use App\Modules\Ledger\Models\MonetisationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MonetisationPolicy>
 */
class MonetisationPolicyFactory extends Factory
{
    protected $model = MonetisationPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::headline($this->faker->unique()->word().' '.$this->faker->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => $this->faker->sentence(),
            'commission_type' => CommissionType::Percentage,
            'commission_percent' => '7.50',
            'commission_flat_ngwee' => 0,
            'addon_fee_ngwee' => 0,
            'referral_fee_percent' => '0.00',
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * The policy sellers fall back to. Only one row may carry this, so a test
     * using it should be the only thing creating a default.
     */
    public function default(): static
    {
        return $this->state(['is_default' => true, 'name' => 'Standard', 'slug' => 'standard']);
    }

    public function percentage(string $percent): static
    {
        return $this->state([
            'commission_type' => CommissionType::Percentage,
            'commission_percent' => $percent,
        ]);
    }

    public function flat(int $ngwee): static
    {
        return $this->state([
            'commission_type' => CommissionType::Flat,
            'commission_flat_ngwee' => $ngwee,
        ]);
    }

    public function withAddonFee(int $ngwee): static
    {
        return $this->state(['addon_fee_ngwee' => $ngwee]);
    }

    public function withReferralFee(string $percent): static
    {
        return $this->state(['referral_fee_percent' => $percent]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
