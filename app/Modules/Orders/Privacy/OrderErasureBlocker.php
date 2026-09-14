<?php

declare(strict_types=1);

namespace App\Modules\Orders\Privacy;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Privacy\Contracts\ErasureBlocker;

/**
 * Holds an erasure while an order is still in flight.
 *
 * Erasing somebody mid-order is not a privacy win. The seller is holding a
 * part for a buyer who no longer exists, the money is in escrow with nobody
 * left to confirm receipt, and the dispute window is still open. The Act
 * allows processing to continue where it is necessary to perform a contract,
 * and an order awaiting collection is exactly that.
 *
 * The hold is temporary and self-clearing: once the last order completes or
 * cancels, the nightly sweep finds nothing here and the erasure proceeds.
 *
 * The message is written to the account holder, not to the log, because they
 * are the one who has to decide whether to wait or to go and collect the part.
 */
class OrderErasureBlocker implements ErasureBlocker
{
    public function blocksErasureOf(User $user): ?string
    {
        $inFlight = Order::query()
            ->where('user_id', $user->getKey())
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->whereNull('closed_at')
            ->count();

        if ($inFlight > 0) {
            return trans_choice(
                '{1} You have an order that is still in progress. Your account will be deleted once it is finished.'
                    .'|[2,*] You have :count orders that are still in progress. Your account will be deleted once they are finished.',
                $inFlight,
                ['count' => $inFlight],
            );
        }

        $disputes = OrderDispute::query()
            ->where('opened_by', $user->getKey())
            ->whereNull('resolved_at')
            ->count();

        if ($disputes > 0) {
            return trans_choice(
                '{1} You have an open dispute. Your account will be deleted once it is settled.'
                    .'|[2,*] You have :count open disputes. Your account will be deleted once they are settled.',
                $disputes,
                ['count' => $disputes],
            );
        }

        return null;
    }
}
