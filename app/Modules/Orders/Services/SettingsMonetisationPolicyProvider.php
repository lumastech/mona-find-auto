<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\MonetisationPolicyProvider;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Modules\Sellers\Models\Seller;

/**
 * Platform-default commercial terms, for every seller alike.
 *
 * The floor beneath Ledger's LedgerMonetisationPolicyProvider, which is what
 * actually answers in a running deployment. This one is reached only when the
 * Ledger module is disabled — nothing in checkout or the state machine can
 * tell the difference either way.
 *
 * Keeping it honest matters more than it looks: an order snapshotted today
 * under the defaults carries those defaults forever, so the numbers this
 * returns have to be the real ones, not zeroes standing in for a lookup that
 * failed.
 */
class SettingsMonetisationPolicyProvider implements MonetisationPolicyProvider
{
    public function forSeller(Seller $seller): MonetisationSnapshot
    {
        return MonetisationSnapshot::fromSettings();
    }
}
