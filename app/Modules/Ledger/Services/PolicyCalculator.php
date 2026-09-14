<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Support\MonetisationBreakdown;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Support\Money\Money;

/**
 * Turns a set of terms and an order value into the figures every recipe,
 * invoice and statement quotes.
 *
 * The arithmetic itself lives on MonetisationSnapshot and is not repeated
 * here. That is deliberate: the snapshot is what an order carries for the
 * rest of its life, so "what does MonaFind take" has to have exactly one
 * implementation and it has to be the one the order is holding. This class
 * arranges those figures, decides what is charged against what, and applies
 * the reserve.
 *
 * ## Rounding
 *
 * HALF-UP, everywhere, and only ever applied to the platform's own figures.
 *
 * Half-up rather than banker's rounding because these numbers appear on a VAT
 * invoice: a seller re-deriving 7.5% of K 100.50 by hand gets K 7.54, and an
 * invoice that says K 7.53 because the ngwee happened to land on an even
 * number is an invoice somebody has to ring up about. Banker's rounding is
 * the better choice when many independent roundings must not accumulate a
 * bias; here each order is rounded twice at most, and reproducibility by hand
 * is worth more than a bias of half a ngwee per order.
 *
 * Applied to the platform's figures ONLY. The seller's net is never rounded —
 * it is defined as the gross minus what the platform took, so whatever the
 * rounding did, the two halves still add up to what the buyer paid. Rounding
 * both sides independently is what loses a ngwee, and on a ledger a lost
 * ngwee is an entry that does not balance.
 */
class PolicyCalculator
{
    /**
     * Break an order value down under a set of terms.
     *
     * Fees are charged against the goods, never against the delivery: MonaFind
     * takes commission on parts, not on a courier's time. The delivery fee
     * therefore passes through to the seller in full.
     *
     * @param  Money  $goods  The goods value — the order's items total.
     * @param  Money  $delivery  The delivery fee, which the seller keeps whole.
     * @param  bool  $referred  Whether this order arrived through a referral partner.
     * @param  bool  $withholdReserve  True for a direct-settlement seller, whose payout
     *                                 is held back by the rolling reserve percentage.
     */
    public function calculate(
        MonetisationSnapshot $snapshot,
        Money $goods,
        Money $delivery,
        bool $referred = false,
        bool $withholdReserve = false,
    ): MonetisationBreakdown {
        $gross = $goods->plus($delivery);

        $commission = $snapshot->commissionOn($goods);
        $vat = $snapshot->vatOnCommission($goods);
        $addon = $snapshot->addonFee;
        $referral = $snapshot->referralFeeOn($goods, $referred);

        $take = $commission->plus($vat)->plus($addon)->plus($referral);

        /*
         * A flat commission or a fixed add-on fee can exceed a very small
         * order. The platform declines to charge more than the order was
         * worth — a pricing decision rather than a debt to chase — and the
         * shortfall is shared across the four fees in proportion rather than
         * taken off one of them, so the invoice still adds up to what was
         * actually deducted. Money::allocate() splits without losing a ngwee.
         */
        if ($take->greaterThan($gross)) {
            [$commission, $vat, $addon, $referral] = $gross->allocate([
                $commission->ngwee,
                $vat->ngwee,
                $addon->ngwee,
                $referral->ngwee,
            ]);

            $take = $gross;
        }

        $sellerNet = $gross->minus($take);

        /*
         * The reserve is a share of the whole order, but it can only ever be
         * taken out of what the seller was actually going to receive. The
         * clamp above means the net is never negative, so this one only ever
         * bites on an order whose fees ate most of it.
         */
        $reserve = $withholdReserve
            ? Money::min($snapshot->reserveOn($gross), $sellerNet)
            : Money::zero();

        return new MonetisationBreakdown(
            goods: $goods,
            delivery: $delivery,
            gross: $gross,
            commission: $commission,
            addonFee: $addon,
            referralFee: $referral,
            vatOnCommission: $vat,
            sellerNet: $sellerNet,
            reserve: $reserve,
            payableToSeller: $sellerNet->minus($reserve),
            referred: $referred,
        );
    }

    /**
     * Break an order down under the terms IT carries.
     *
     * Reads `orders.monetisation_snapshot` and never a seller's live policy,
     * which is the rule the whole snapshot exists to enforce: an administrator
     * raising the default commission today must not change what MonaFind
     * earned on a sale settled last week.
     *
     * @param  Money|null  $goods  Override the goods value — used by refunds,
     *                             which recompute the take against what the
     *                             buyer actually kept.
     */
    public function forOrder(
        Order $order,
        bool $withholdReserve = false,
        ?Money $goods = null,
        ?Money $delivery = null,
    ): MonetisationBreakdown {
        $snapshot = $order->monetisation() ?? MonetisationSnapshot::fromArray([]);

        return $this->calculate(
            snapshot: $snapshot,
            goods: $goods ?? $order->items_total_ngwee,
            delivery: $delivery ?? $order->delivery_fee_ngwee,
            referred: $this->wasReferred($order),
            withholdReserve: $withholdReserve,
        );
    }

    /**
     * Whether an order arrived through a referral partner.
     *
     * Referral tracking is a later release; until it exists no order carries
     * a partner, and charging a referral fee to every seller because the
     * platform cannot tell would be worse than charging none. The snapshot
     * already carries the rate, so switching this on later changes one method
     * and nothing else.
     */
    private function wasReferred(Order $order): bool
    {
        return (bool) ($order->monetisation_snapshot['referred'] ?? false);
    }
}
