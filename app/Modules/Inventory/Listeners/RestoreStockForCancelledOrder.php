<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Support\OrderStockLine;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A cancelled order puts its stock back.
 *
 * The ledger restores what the sale actually took rather than what the order
 * said it wanted — a line that clamped at zero because the shelf was short
 * must not come back as a full restock, or a cancellation would invent stock
 * the seller never had.
 */
class RestoreStockForCancelledOrder implements ShouldQueue
{
    public string $queue = 'payments';

    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(OrderCancelled $event): void
    {
        $this->ledger->applyOrderCancelled(
            OrderStockLine::toQuantities($event->lines),
            $event->orderId,
            $event->reference,
            $event->reason,
        );
    }
}
