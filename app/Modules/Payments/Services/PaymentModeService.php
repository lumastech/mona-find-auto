<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Support\DirectEligibility;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Models\Seller;

/**
 * Who is allowed to be paid directly, and who has stopped being allowed.
 *
 * ## The trade
 *
 * ESCROW is safe for buyers and slow for sellers: the platform holds the money
 * until the buyer confirms. DIRECT is the reward for a good track record —
 * paid on payment, less a rolling reserve. The risk it carries is that a
 * seller who stops delivering has already been paid, which is exactly what
 * the reserve and the automatic reversion below exist to contain.
 *
 * ## Recommendation, not enforcement
 *
 * `eligibility()` computes the four criteria from the brief — verified, ≥20
 * completed orders, <2% disputes, ≥60 days active — and an administrator can
 * override any of them with a reason. That is on purpose: a seller MonaFind
 * knows personally, or a strategic supplier, is a judgement no rule captures.
 * What the override cannot be is silent, so a reason is required and audited.
 *
 * ## Reversion is automatic and one-way
 *
 * The scheduled sweep only ever moves sellers from Direct to Escrow, never
 * back. Losing trust is automatic; regaining it is a decision. A seller
 * auto-reverted at 2am and auto-restored at 3am because a dispute closed
 * would be worse than useless.
 *
 * Changing a seller's mode never touches orders already paid — every order
 * carries its own snapshot.
 */
class PaymentModeService
{
    /**
     * How a seller measures against the direct-settlement criteria.
     */
    public function eligibility(Seller $seller): DirectEligibility
    {
        $completed = $this->completedOrderCount($seller);

        return new DirectEligibility(
            isVerified: $seller->isVerified(),
            completedOrders: $completed,
            requiredOrders: (int) settings('risk.direct_min_completed_orders', 20),
            disputeRatePercent: $this->disputeRatePercent($seller),
            maximumDisputeRatePercent: (float) settings('risk.direct_max_dispute_percent', 2),
            daysActive: $this->daysActive($seller),
            requiredDaysActive: (int) settings('risk.direct_min_days_active', 60),
        );
    }

    /**
     * Set a seller's payment mode.
     *
     * A reason is required whenever the decision goes against the
     * recommendation, because "why is this seller on direct" is the first
     * question anyone asks after a bad debt and the answer has to be on the
     * record rather than in somebody's memory.
     */
    public function setMode(
        Seller $seller,
        PaymentMode $mode,
        ?User $actor = null,
        ?string $reason = null,
    ): Seller {
        if ($seller->payment_mode === $mode) {
            return $seller;
        }

        $eligibility = $this->eligibility($seller);
        $before = ['payment_mode' => $seller->payment_mode->value];

        $seller->forceFill(['payment_mode' => $mode])->save();

        audit($actor, 'sellers.payment_mode.changed', $seller, $before, [
            'payment_mode' => $mode->value,
            'was_recommended' => $eligibility->isEligible(),
            'eligibility' => $eligibility->toArray(),
        ], $reason);

        return $seller->refresh();
    }

    /**
     * Send a seller back to escrow because their dispute rate went too high.
     *
     * Separate from setMode so it reads as its own thing in the audit trail:
     * "the platform did this because of a threshold" is a different event from
     * "a person decided this", and they are investigated differently.
     */
    public function revertToEscrow(Seller $seller, float $disputeRate, float $threshold): Seller
    {
        $before = ['payment_mode' => $seller->payment_mode->value];

        $seller->forceFill(['payment_mode' => PaymentMode::Escrow])->save();

        audit(null, 'sellers.payment_mode.auto_reverted', $seller, $before, [
            'payment_mode' => PaymentMode::Escrow->value,
            'dispute_rate_percent' => round($disputeRate, 2),
            'threshold_percent' => $threshold,
        ], __('Dispute rate of :rate% exceeded the :threshold% threshold.', [
            'rate' => number_format($disputeRate, 2),
            'threshold' => number_format($threshold, 2),
        ]));

        return $seller->refresh();
    }

    /**
     * Whether a direct seller has crossed the reversion threshold.
     *
     * Sellers with almost no history are exempt: one dispute out of two orders
     * is a 50% rate and means nothing at all, and reverting on it would punish
     * new sellers for a rounding artefact.
     */
    public function shouldRevert(Seller $seller): bool
    {
        if ($seller->payment_mode !== PaymentMode::Direct) {
            return false;
        }

        $minimumOrders = (int) settings('risk.dispute_rate_min_orders', 10);

        if ($this->completedOrderCount($seller) < $minimumOrders) {
            return false;
        }

        return $this->disputeRatePercent($seller) > $this->threshold();
    }

    /**
     * The dispute-rate threshold, in percent.
     */
    public function threshold(): float
    {
        return (float) settings('risk.dispute_rate_threshold_percent', 2);
    }

    /**
     * Disputes as a percentage of the seller's paid orders over the window.
     *
     * Measured over a trailing window rather than all time, so a seller who
     * had a bad month a year ago is not held to it forever — and so a seller
     * going bad now shows up quickly instead of being diluted by their whole
     * history.
     */
    public function disputeRatePercent(Seller $seller): float
    {
        $since = now()->subDays((int) settings('risk.reserve_trailing_days', 30));

        $orders = Order::query()
            ->where('seller_id', $seller->getKey())
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->count();

        if ($orders === 0) {
            return 0.0;
        }

        $disputed = Order::query()
            ->where('seller_id', $seller->getKey())
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->whereNotNull('disputed_at')
            ->count();

        return ($disputed / $orders) * 100;
    }

    private function completedOrderCount(Seller $seller): int
    {
        return Order::query()
            ->where('seller_id', $seller->getKey())
            ->where('status', OrderStatus::Completed)
            ->count();
    }

    private function daysActive(Seller $seller): int
    {
        $from = $seller->verified_at ?? $seller->created_at;

        return $from === null ? 0 : (int) $from->diffInDays(now());
    }
}
