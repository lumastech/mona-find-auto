<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Support\OrderStockLine;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Stock comes down when the money arrives.
 *
 * Queued, because the payment webhook that fires this has to answer Lenco
 * quickly and a shop with twelve lines on an order is twelve locked
 * transactions. Safe to queue precisely because the ledger is idempotent: a
 * retry after a worker timeout finds the movements already recorded and
 * changes nothing.
 */
class DecrementStockForPaidOrder implements ShouldQueue
{
    public string $queue = 'payments';

    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(OrderPaid $event): void
    {
        $this->ledger->applyOrderPaid(
            OrderStockLine::toQuantities($event->lines),
            $event->orderId,
            $event->reference,
        );
    }
}
