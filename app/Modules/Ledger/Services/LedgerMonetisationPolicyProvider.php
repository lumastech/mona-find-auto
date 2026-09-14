<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Modules\Orders\Contracts\MonetisationPolicyProvider;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Modules\Sellers\Models\Seller;

/**
 * The real answer to "what terms does this seller trade on".
 *
 * Orders declared MonetisationPolicyProvider and shipped a stub that returned
 * the platform settings for everybody. This replaces it, because per-seller
 * policies now exist — the binding in LedgerServiceProvider is the whole
 * change, and nothing in checkout or the state machine knows it happened.
 *
 * The fallback chain matters more than it looks. A seller's own policy, then
 * the default policy row, then the raw settings. That last step is not
 * defensive padding: on a database mid-deployment, before the policy seeder
 * has run, an order still has to be priceable, and pricing it at zero
 * commission because a lookup table was empty would be a silent gift.
 */
class LedgerMonetisationPolicyProvider implements MonetisationPolicyProvider
{
    public function forSeller(Seller $seller): MonetisationSnapshot
    {
        return $this->policyFor($seller)?->toSnapshot() ?? MonetisationSnapshot::fromSettings();
    }

    /**
     * The policy row in force for a seller, or null if there is none to find.
     *
     * An inactive policy still applies to the sellers already on it —
     * deactivating a policy takes it off the list of things new sellers can
     * be put on, and does not silently reprice the shops that are on it.
     */
    public function policyFor(Seller $seller): ?MonetisationPolicy
    {
        if ($seller->monetisation_policy_id !== null) {
            $assigned = MonetisationPolicy::query()->find($seller->monetisation_policy_id);

            if ($assigned !== null) {
                return $assigned;
            }
        }

        return MonetisationPolicy::default();
    }
}
