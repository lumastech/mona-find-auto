<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

use App\Support\Money\Money;

/**
 * What happened to the money over a stretch of time.
 *
 * Flows, not positions — every figure here is a movement counted between two
 * dates, and every one of them is a sum of journal lines. Nothing on this
 * object is derived from an order, a payment row or a payout; see
 * FinanceMetrics for why that distinction is the point of the whole module.
 *
 * VAT is deliberately absent from `netRevenue`. It is collected on MonaFind's
 * commission and owed onward to ZRA, so counting it as revenue would inflate
 * the platform's earnings by money that was never its own.
 */
final readonly class FinanceTotals
{
    public function __construct(
        /** What buyers paid — cash in on payment recipes. */
        public Money $gmv,
        /** Orders that took a payment in the window. */
        public int $orderCount,
        public Money $commission,
        public Money $addonFees,
        public Money $referralFees,
        /** Collected on the commission, owed to ZRA. Never revenue. */
        public Money $vatOnCommission,
        /** Cash that went back out to buyers, however it was funded. */
        public Money $refundsToBuyers,
        /** The share of those refunds the platform funded itself. */
        public Money $refundsAbsorbed,
        public Money $lencoFees,
    ) {}

    public static function zero(): self
    {
        return new self(
            Money::zero(), 0, Money::zero(), Money::zero(), Money::zero(),
            Money::zero(), Money::zero(), Money::zero(), Money::zero(),
        );
    }

    /**
     * What MonaFind actually earned: its three fee lines, less what it gave
     * back out of its own pocket and what the gateway charged to move it all.
     */
    public function netRevenue(): Money
    {
        return $this->grossRevenue()
            ->minus($this->refundsAbsorbed)
            ->minus($this->lencoFees);
    }

    /**
     * The three fee lines before any cost is taken off.
     */
    public function grossRevenue(): Money
    {
        return $this->commission->plus($this->addonFees)->plus($this->referralFees);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'gmvNgwee' => $this->gmv->ngwee,
            'orderCount' => $this->orderCount,
            'commissionNgwee' => $this->commission->ngwee,
            'addonFeesNgwee' => $this->addonFees->ngwee,
            'referralFeesNgwee' => $this->referralFees->ngwee,
            'vatOnCommissionNgwee' => $this->vatOnCommission->ngwee,
            'refundsToBuyersNgwee' => $this->refundsToBuyers->ngwee,
            'refundsAbsorbedNgwee' => $this->refundsAbsorbed->ngwee,
            'lencoFeesNgwee' => $this->lencoFees->ngwee,
            'grossRevenueNgwee' => $this->grossRevenue()->ngwee,
            'netRevenueNgwee' => $this->netRevenue()->ngwee,
        ];
    }

    /**
     * Apportion every figure by a ratio, for a breakdown that has to split an
     * order across categories.
     *
     * Money::multiplyByRatio rounds each figure independently, which is
     * correct here: these are shares of a whole for reporting, and the caller
     * (FinanceMetrics::byCategory) uses allocate() on the totals it cares
     * about rather than trusting a sum of rounded shares.
     */
    public function apportion(int $numerator, int $denominator): self
    {
        if ($denominator <= 0) {
            return self::zero();
        }

        $share = static fn (Money $amount): Money => $amount->multiplyByRatio($numerator, $denominator);

        return new self(
            gmv: $share($this->gmv),
            orderCount: $this->orderCount,
            commission: $share($this->commission),
            addonFees: $share($this->addonFees),
            referralFees: $share($this->referralFees),
            vatOnCommission: $share($this->vatOnCommission),
            refundsToBuyers: $share($this->refundsToBuyers),
            refundsAbsorbed: $share($this->refundsAbsorbed),
            lencoFees: $share($this->lencoFees),
        );
    }

    public function plus(self $other): self
    {
        return new self(
            gmv: $this->gmv->plus($other->gmv),
            orderCount: $this->orderCount + $other->orderCount,
            commission: $this->commission->plus($other->commission),
            addonFees: $this->addonFees->plus($other->addonFees),
            referralFees: $this->referralFees->plus($other->referralFees),
            vatOnCommission: $this->vatOnCommission->plus($other->vatOnCommission),
            refundsToBuyers: $this->refundsToBuyers->plus($other->refundsToBuyers),
            refundsAbsorbed: $this->refundsAbsorbed->plus($other->refundsAbsorbed),
            lencoFees: $this->lencoFees->plus($other->lencoFees),
        );
    }
}
