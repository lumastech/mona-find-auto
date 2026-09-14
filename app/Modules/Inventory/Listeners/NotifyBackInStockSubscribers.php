<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Events\StockLevelChanged;
use App\Modules\Inventory\Services\BackInStockService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the buyers who asked to be told.
 *
 * Only on the crossing from empty to not-empty. A shelf going from two to
 * five is a restock nobody was waiting for; a shelf going from zero to one is
 * the thing every subscriber signed up for.
 */
class NotifyBackInStockSubscribers implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly BackInStockService $subscriptions) {}

    public function handle(StockLevelChanged $event): void
    {
        if (! $event->cameBackInStock()) {
            return;
        }

        $this->subscriptions->notifySubscribers($event->variant);
    }
}
