<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Database\Seeders;

use App\Modules\Ledger\Enums\CommissionType;
use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Support\Money\Money;
use Illuminate\Database\Seeder;

/**
 * The default policy every seller starts on.
 *
 * Its figures come from the settings the SettingsSeeder wrote, so a fresh
 * install prices orders the same way whether the policy row exists or not —
 * and an administrator who has already changed the settings does not find the
 * seeder quietly disagreeing with them.
 *
 * Re-running is safe and deliberately conservative: an existing default
 * policy is left exactly as it is. Its rates are a commercial decision
 * somebody made, and a deployment is not the moment to overwrite one.
 */
class MonetisationPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (MonetisationPolicy::query()->where('is_default', true)->exists()) {
            return;
        }

        MonetisationPolicy::query()->create([
            'name' => 'Standard',
            'slug' => 'standard',
            'description' => 'The platform default. Sellers without an override trade on these terms.',
            'commission_type' => CommissionType::tryFrom((string) settings('monetisation.commission_type', 'percentage'))
                ?? CommissionType::Percentage,
            'commission_percent' => (string) settings('monetisation.commission_percent', '7.50'),
            'commission_flat_ngwee' => settings()->money('monetisation.commission_flat', Money::zero())->ngwee,
            'addon_fee_ngwee' => settings()->money('monetisation.addon_fee', Money::zero())->ngwee,
            'referral_fee_percent' => (string) settings('monetisation.referral_fee_percent', '0.00'),
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
