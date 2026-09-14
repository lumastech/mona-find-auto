<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Services\SettingsMonetisationPolicyProvider;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Modules\Sellers\Models\Seller;

/**
 * The commercial terms a given seller trades on.
 *
 * Orders needs these at exactly one moment — when a payment settles and the
 * terms are frozen onto the order — but the terms themselves belong to the
 * Ledger module, which owns monetisation policies and their per-seller
 * assignment. Ledger binds this interface; SettingsMonetisationPolicyProvider
 * is the fallback for a deployment where it has not, and answers with the
 * platform defaults.
 *
 * The same pattern Shopping uses for SellerEnquiryChannel and Search for
 * SellerReputationProvider: the consumer declares what it needs, the owner
 * implements it later.
 *
 * @see SettingsMonetisationPolicyProvider
 */
interface MonetisationPolicyProvider
{
    /**
     * The terms in force for this seller RIGHT NOW.
     *
     * Only ever called at snapshot time. Anything that needs the terms of an
     * order already placed reads the snapshot on the order instead.
     */
    public function forSeller(Seller $seller): MonetisationSnapshot;
}
