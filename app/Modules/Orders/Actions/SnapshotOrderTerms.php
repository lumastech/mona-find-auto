<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Contracts\MonetisationPolicyProvider;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonInterface;

/**
 * Freeze an order's commercial terms at the moment its money arrives.
 *
 * This is the single most consequential write in the module. Four things that
 * live elsewhere and change there — the seller's payment mode, their
 * monetisation policy, the platform's escrow window and its seller-confirmation
 * window — are copied onto the order here and are never read from their
 * sources again for that order's lifetime.
 *
 * Without it, an administrator raising the default commission on Thursday
 * would change what MonaFind earned on Wednesday's sales; a seller moved from
 * escrow to direct payment would have money released on orders a buyer is
 * still waiting for; and lengthening the pickup window would push back
 * deadlines on orders already sitting on a counter.
 *
 * It is deliberately a separate class from the state machine that calls it,
 * because Prompt 09 brings real payments and this is the seam they attach to.
 */
class SnapshotOrderTerms
{
    public function __construct(private readonly MonetisationPolicyProvider $policies) {}

    /**
     * Fill the snapshot columns. Does not save — the caller is inside the
     * transaction that writes the status change, and the two belong together.
     */
    public function apply(Order $order, ?CarbonInterface $at = null): Order
    {
        $at ??= now();

        if ($order->snapshot_at !== null) {
            /*
             * Already frozen. A payment webhook arriving twice must not
             * re-freeze terms against a policy that changed in between —
             * the first arrival is the one that counts.
             */
            return $order;
        }

        $order->loadMissing('seller');

        $confirmWindowHours = (int) settings('orders.seller_confirm_window_hours', 24);

        $order->fill([
            'payment_mode' => $order->seller->payment_mode,
            'monetisation_snapshot' => $this->policies->forSeller($order->seller)->toArray(),
            'auto_complete_window_days' => $order->fulfilment_method->autoCompleteWindowDays(),
            'seller_confirm_window_hours' => $confirmWindowHours,
            'snapshot_at' => $at,

            /*
             * The seller's deadline starts now. Stored rather than derived so
             * the sweep is an index scan and so a window lengthened tomorrow
             * cannot move it.
             */
            'confirm_due_at' => $at->copy()->addHours($confirmWindowHours),
        ]);

        return $order;
    }
}
