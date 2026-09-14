<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Services\PolicyCalculator;
use App\Support\Money\Money;

/**
 * What an order is worth to each party, to the ngwee.
 *
 * The one arrangement of figures every recipe posts from, every invoice
 * quotes and every seller statement shows. Its whole point is the identity
 * below, which holds by construction rather than by rounding luck:
 *
 *     gross = payableToSeller + reserve + commission + vat + addon + referral
 *
 * `sellerNet` is DEFINED as gross minus the platform's take, never computed
 * from its own percentage, so nothing rounds twice and no ngwee is lost
 * between the buyer's card and the accounts. Push the rounding into the net
 * instead — take a percentage for the seller and another for the platform —
 * and the two halves disagree by a ngwee on roughly half of all orders.
 *
 * Delivery passes through untouched: MonaFind takes commission on the goods,
 * not on a courier's time, so `goods` and not `gross` is what every fee is
 * charged against.
 *
 * @see PolicyCalculator
 */
final readonly class MonetisationBreakdown
{
    public function __construct(
        /** The goods value — what every fee is charged against. */
        public Money $goods,
        /** The delivery fee, which the seller keeps in full. */
        public Money $delivery,
        /** What the buyer paid: goods plus delivery. */
        public Money $gross,
        public Money $commission,
        public Money $addonFee,
        public Money $referralFee,
        public Money $vatOnCommission,
        /** Gross less everything MonaFind takes, before any reserve. */
        public Money $sellerNet,
        /** Withheld from a direct-settlement seller and released later. */
        public Money $reserve,
        /** What the seller can actually be paid now. */
        public Money $payableToSeller,
        /** Whether a referral fee applied at all. */
        public bool $referred,
    ) {}

    /**
     * Everything MonaFind takes off this order.
     */
    public function platformTake(): Money
    {
        return $this->commission
            ->plus($this->addonFee)
            ->plus($this->referralFee)
            ->plus($this->vatOnCommission);
    }

    /**
     * What MonaFind actually earned, which excludes the VAT — that is ZRA's
     * and only ever passed through.
     */
    public function revenue(): Money
    {
        return $this->commission->plus($this->addonFee)->plus($this->referralFee);
    }

    /**
     * What the seller is invoiced for: commission plus its VAT. The add-on
     * and referral fees are deductions from a payout rather than services
     * MonaFind invoices, which is why they are not on this line.
     */
    public function invoiceTotal(): Money
    {
        return $this->commission->plus($this->vatOnCommission);
    }

    /**
     * The identity the whole module rests on. Asserted in the tests and
     * cheap enough to be worth calling from a reconciliation job.
     */
    public function balances(): bool
    {
        return $this->gross->equals(
            $this->payableToSeller
                ->plus($this->reserve)
                ->plus($this->platformTake()),
        );
    }

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'goods_ngwee' => $this->goods->ngwee,
            'delivery_ngwee' => $this->delivery->ngwee,
            'gross_ngwee' => $this->gross->ngwee,
            'commission_ngwee' => $this->commission->ngwee,
            'addon_fee_ngwee' => $this->addonFee->ngwee,
            'referral_fee_ngwee' => $this->referralFee->ngwee,
            'vat_on_commission_ngwee' => $this->vatOnCommission->ngwee,
            'seller_net_ngwee' => $this->sellerNet->ngwee,
            'reserve_ngwee' => $this->reserve->ngwee,
            'payable_to_seller_ngwee' => $this->payableToSeller->ngwee,
            'referred' => $this->referred,
        ];
    }
}
