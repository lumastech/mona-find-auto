<?php

declare(strict_types=1);

namespace App\Modules\Orders\Database\Factories;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Orders\Models\TermsAcceptance;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermsAcceptance>
 */
class TermsAcceptanceFactory extends Factory
{
    protected $model = TermsAcceptance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'order_group_id' => OrderGroup::factory(),
            'user_id' => User::factory(),
            'seller_id' => Seller::factory(),
            'policies' => [],
            'platform_terms_version' => '1',
            'minimum_refund_days' => (int) settings('policies.minimum_refund_days', 3),
            'minimum_refund_statement' => (string) settings('policies.minimum_refund_statement', ''),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'accepted_at' => now(),
            'created_at' => now(),
        ];
    }

    /**
     * Record the acceptance against real policy rows, so a test can republish
     * one afterwards and check the recorded version did not move.
     *
     * @param  iterable<int, SellerPolicy>  $policies
     */
    public function acceptingPolicies(iterable $policies): static
    {
        $fingerprint = [];

        foreach ($policies as $policy) {
            $fingerprint[] = [
                'policy_id' => $policy->getKey(),
                'type' => $policy->type->value,
                'version' => $policy->version,
                'effective_from' => $policy->effective_from->toIso8601String(),
            ];
        }

        return $this->state(['policies' => $fingerprint]);
    }

    public function forPolicyType(PolicyType $type, int $policyId, int $version): static
    {
        return $this->state([
            'policies' => [[
                'policy_id' => $policyId,
                'type' => $type->value,
                'version' => $version,
                'effective_from' => now()->toIso8601String(),
            ]],
        ]);
    }
}
