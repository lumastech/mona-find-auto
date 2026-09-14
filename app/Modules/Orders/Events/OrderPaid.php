<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Inventory\Listeners\DecrementStockForPaidOrder;
use App\Modules\Orders\Support\OrderStockLine;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A buyer's money has arrived and the order is real.
 *
 * Stock comes down here rather than when a cart is filled or an order is
 * drafted: an unpaid basket that held stock would let anyone empty a shop's
 * shelves without spending a ngwee.
 *
 * The Orders module fires this; Inventory listens for it. It is declared here
 * because the module that fires an event owns its shape — Inventory only
 * needs the lines, and stating the contract now means the two modules were
 * never written against different assumptions about it.
 *
 * @see DecrementStockForPaidOrder
 */
class OrderPaid
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, OrderStockLine>  $lines  What was bought, per variant.
     * @param  string  $reference  The payment reference, e.g. "MFA-1042-1". Written onto
     *                             each stock movement so a shelf can be reconciled
     *                             against the money that moved it.
     */
    public function __construct(
        public int $orderId,
        public string $reference,
        public array $lines,
    ) {}
}
