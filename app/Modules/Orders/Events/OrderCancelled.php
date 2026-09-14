<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Inventory\Listeners\RestoreStockForCancelledOrder;
use App\Modules\Orders\Support\OrderStockLine;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A paid order will not be fulfilled, so its stock goes back on the shelf.
 *
 * Fired for a cancellation and for a refund that returns the goods — both are
 * the same thing as far as a shelf is concerned. Inventory restores exactly
 * what the matching OrderPaid took, and does it once: the restore is keyed on
 * the order, so a cancellation processed twice does not double a seller's
 * stock.
 *
 * @see RestoreStockForCancelledOrder
 */
class OrderCancelled
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, OrderStockLine>  $lines  What was bought, per variant.
     */
    public function __construct(
        public int $orderId,
        public string $reference,
        public array $lines,
        public ?string $reason = null,
    ) {}
}
