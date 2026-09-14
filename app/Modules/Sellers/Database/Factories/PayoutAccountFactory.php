<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Database\Factories;

use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Sellers\Enums\PayoutMethod;
use App\Modules\Sellers\Models\PayoutAccount;
use App\Modules\Sellers\Models\Seller;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutAccount>
 */
class PayoutAccountFactory extends Factory
{
    protected $model = PayoutAccount::class;

    /**
     * Accounts come out already resolved at the gateway, because an
     * unresolved account is one the service would have refused to save.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory(),
            'method' => PayoutMethod::Bank,
            'label' => 'Main account',
            'beneficiary_name' => fake()->company(),
            'account_number' => (string) fake()->unique()->numerify('##########'),
            'bank_code' => '01',
            'bank_name' => 'Zanaco',
            'bank_branch' => fake()->citySuffix().' Branch',
            'resolved_name' => strtoupper(fake()->company()),
            'lenco_recipient_id' => 'fake_rcp_'.fake()->unique()->lexify('????????????????'),
            'resolved_at' => now(),
            'is_default' => false,
        ];
    }

    public function mobileMoney(?MobileNetwork $network = null): static
    {
        return $this->state([
            'method' => PayoutMethod::MobileMoney,
            'mobile_number' => UserFactory::zambianMobile(),
            'network' => $network ?? MobileNetwork::Mtn,
            'account_number' => null,
            'bank_code' => null,
            'bank_name' => null,
            'bank_branch' => null,
        ]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    /**
     * An account the gateway never confirmed. Payouts skip these.
     */
    public function unresolved(): static
    {
        return $this->state([
            'resolved_name' => null,
            'lenco_recipient_id' => null,
            'resolved_at' => null,
        ]);
    }
}
