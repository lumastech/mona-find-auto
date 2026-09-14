<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Contracts\VatRateProvider;
use App\Support\Money\Money;
use App\Support\Money\RoundingMode;

/**
 * A seller's commercial terms, frozen at the moment their money arrived.
 *
 * Every figure MonaFind takes off an order is derived from this object and
 * from nothing else. It is written onto the order at payment time and read
 * back for the rest of that order's life, including months later when a
 * refund is worked out — because the alternative, reading the seller's
 * current terms, means an administrator adjusting a default quietly rewrites
 * what the platform earned on orders that are already settled.
 *
 * The arithmetic lives here rather than in the ledger for the same reason the
 * snapshot exists: there must be exactly one implementation of "what does
 * MonaFind take", and it must be the one the order carries.
 *
 * Rounding is stated at every call site, as Money requires. Commission and
 * VAT both round half-up, which favours the platform by at most one ngwee per
 * order and is what an invoice line has to do to be reproducible.
 */
final readonly class MonetisationSnapshot
{
    public function __construct(
        /** Either "percentage" or "flat". */
        public string $commissionType,
        /** Decimal string, e.g. "7.50". Used when the type is percentage. */
        public string $commissionPercent,
        /** Used when the type is flat. */
        public Money $commissionFlat,
        /** Charged per order on top of commission. */
        public Money $addonFee,
        /** Decimal string. Charged only on orders that arrived through a referral. */
        public string $referralFeePercent,
        /** Decimal string. MonaFind's VAT, on its commission only. */
        public string $vatOnCommissionPercent,
        /** Decimal string. Withheld from direct-payment sellers. */
        public string $reservePercent,
    ) {}

    /**
     * The platform defaults, as they stand right now.
     *
     * Only ever called at snapshot time. Anything reading these values off an
     * order that has already been paid is reading the wrong thing.
     */
    public static function fromSettings(): self
    {
        return new self(
            commissionType: (string) settings('monetisation.commission_type', 'percentage'),
            commissionPercent: (string) settings('monetisation.commission_percent', '0.00'),
            /* Money settings come back as Money already; ->money() states that. */
            commissionFlat: settings()->money('monetisation.commission_flat', Money::zero()),
            addonFee: settings()->money('monetisation.addon_fee', Money::zero()),
            referralFeePercent: (string) settings('monetisation.referral_fee_percent', '0.00'),
            /*
             * The rate is DATED, so it is asked for rather than read off a
             * settings row. VatRateProvider answers with the rate in force
             * today; a rate scheduled for next month is on the schedule and
             * deliberately not in this snapshot yet.
             */
            vatOnCommissionPercent: app(VatRateProvider::class)->percentAt(),
            reservePercent: (string) settings('risk.reserve_percent', '0.00'),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            commissionType: (string) ($snapshot['commission_type'] ?? 'percentage'),
            commissionPercent: (string) ($snapshot['commission_percent'] ?? '0.00'),
            commissionFlat: Money::ofNgwee((int) ($snapshot['commission_flat_ngwee'] ?? 0)),
            addonFee: Money::ofNgwee((int) ($snapshot['addon_fee_ngwee'] ?? 0)),
            referralFeePercent: (string) ($snapshot['referral_fee_percent'] ?? '0.00'),
            vatOnCommissionPercent: (string) ($snapshot['vat_on_commission_percent'] ?? '0.00'),
            reservePercent: (string) ($snapshot['reserve_percent'] ?? '0.00'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commission_type' => $this->commissionType,
            'commission_percent' => $this->commissionPercent,
            'commission_flat_ngwee' => $this->commissionFlat->ngwee,
            'addon_fee_ngwee' => $this->addonFee->ngwee,
            'referral_fee_percent' => $this->referralFeePercent,
            'vat_on_commission_percent' => $this->vatOnCommissionPercent,
            'reserve_percent' => $this->reservePercent,
        ];
    }

    /**
     * MonaFind's commission on a gross order amount.
     */
    public function commissionOn(Money $gross): Money
    {
        return $this->commissionType === 'flat'
            ? $this->commissionFlat
            : $gross->percentage($this->commissionPercent, RoundingMode::HalfUp);
    }

    /**
     * VAT on that commission.
     *
     * Charged on MonaFind's fee and never on the goods: product prices are
     * VAT-inclusive and the seller answers for their own output tax. This is
     * the only tax figure the platform ever calculates.
     */
    public function vatOnCommission(Money $gross): Money
    {
        return $this->commissionOn($gross)->percentage($this->vatOnCommissionPercent, RoundingMode::HalfUp);
    }

    /**
     * The referral fee, if this order came through a partner.
     */
    public function referralFeeOn(Money $gross, bool $referred): Money
    {
        return $referred
            ? $gross->percentage($this->referralFeePercent, RoundingMode::HalfUp)
            : Money::zero();
    }

    /**
     * Everything MonaFind deducts from a gross order amount.
     */
    public function totalDeductions(Money $gross, bool $referred = false): Money
    {
        return $this->commissionOn($gross)
            ->plus($this->vatOnCommission($gross))
            ->plus($this->addonFee)
            ->plus($this->referralFeeOn($gross, $referred));
    }

    /**
     * What reaches the seller, before any rolling reserve.
     *
     * Tax-inclusive from the seller's side: the figure they receive is theirs
     * to account for, and MonaFind's invoice covers the commission alone.
     */
    public function sellerPayout(Money $gross, bool $referred = false): Money
    {
        return $gross->minus($this->totalDeductions($gross, $referred));
    }

    /**
     * The reserve withheld from a direct-payment seller on this order.
     */
    public function reserveOn(Money $gross): Money
    {
        return $gross->percentage($this->reservePercent, RoundingMode::HalfUp);
    }

    /**
     * A plain-language line for the seller's own order page.
     */
    public function commissionDescription(): string
    {
        return $this->commissionType === 'flat'
            ? $this->commissionFlat->format().' per order'
            : $this->commissionPercent.'% of the order value';
    }
}
