<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\VatRateProvider;
use Carbon\CarbonInterface;

/**
 * The flat platform setting, for every date alike.
 *
 * The floor beneath Finance's VatRateSchedule, which is what actually answers
 * in a running deployment. Reached only when the Finance module is disabled,
 * and it ignores the date because a single setting has no history to consult.
 *
 * It returns the real configured rate rather than zero for the same reason
 * SettingsMonetisationPolicyProvider does: an order snapshotted under this
 * fallback carries what it returns forever, and a zero standing in for a
 * lookup that was not wired up is a tax figure nobody can correct later.
 */
class SettingsVatRateProvider implements VatRateProvider
{
    public function percentAt(?CarbonInterface $moment = null): string
    {
        return (string) settings('monetisation.vat_on_commission_percent', '0.00');
    }
}
