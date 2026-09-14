<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Inventory\Events\StockLevelChanged;
use App\Modules\Inventory\Jobs\EvaluateStockFreshness;
use App\Modules\Inventory\Jobs\SendStockConfirmationReminders;
use App\Modules\Inventory\Listeners\DecrementStockForPaidOrder;
use App\Modules\Inventory\Listeners\NotifyBackInStockSubscribers;
use App\Modules\Inventory\Listeners\NotifySellerOfStockLevel;
use App\Modules\Inventory\Listeners\RestoreStockForCancelledOrder;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Modules\Inventory\Policies\StockImportBatchPolicy;
use App\Modules\Inventory\Privacy\InventoryPersonalData;
use App\Modules\Inventory\Services\InventoryConsoleCounters;
use App\Modules\Inventory\Services\StockSummary;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Support\Console\ConsoleCounters;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Inertia\Inertia;

/**
 * Inventory module — what is on the shelf, and whether anyone still believes
 * it.
 *
 * Two jobs, and they are less related than they look. Stock levels are
 * arithmetic: quantities move, under locks, recorded in an append-only
 * ledger. Freshness is a trust problem: a Zambian parts shop sells the same
 * alternator over the counter as on MonaFind, and nobody updates a website
 * mid-transaction — so the platform asks sellers to vouch for their stock
 * every few days and demotes, labels, and finally hides what they do not.
 *
 * Its couplings are two events fired by Orders. Inventory never calls into
 * Orders, and Orders never touches a quantity: money arriving is the event,
 * and moving the stock is this module's business.
 */
class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        $this->registerPersonalData();
        $this->app->make(ConsoleCounters::class)->register(InventoryConsoleCounters::class);

        $this->registerPolicies();
        $this->registerListeners();
        $this->registerSchedule();
        $this->shareStockSummary();
    }

    /**
     * Put the shop's stock position on every seller-portal page.
     *
     * The confirmation banner has to follow a seller around the portal rather
     * than living on one screen they may not visit — a seller who only ever
     * opens Orders would otherwise never learn their listings had been
     * hidden. Sharing it costs two counts, and only inside /seller: the
     * storefront and the admin console never resolve the closure.
     */
    private function shareStockSummary(): void
    {
        Inertia::share('stock', function (Request $request): ?array {
            if (! $request->is('seller', 'seller/*')) {
                return null;
            }

            $seller = $request->user()?->seller;

            return $seller === null ? null : app(StockSummary::class)->for($seller);
        });
    }

    private function registerPolicies(): void
    {
        Gate::policy(StockImportBatch::class, StockImportBatchPolicy::class);
    }

    private function registerListeners(): void
    {
        /*
         * Stock follows the money, in both directions. Orders declares these
         * two events; nothing here knows anything else about an order.
         */
        Event::listen(OrderPaid::class, DecrementStockForPaidOrder::class);
        Event::listen(OrderCancelled::class, RestoreStockForCancelledOrder::class);

        /*
         * One event, three reactions. The low-stock alert, the out-of-stock
         * alert and the back-in-stock notification are the same question
         * asked at different quantities, and answering them off a single
         * event is what stops a shelf that goes 3 → 0 → 3 in a minute sending
         * three contradictory messages.
         */
        Event::listen(StockLevelChanged::class, NotifySellerOfStockLevel::class);
        Event::listen(StockLevelChanged::class, NotifyBackInStockSubscribers::class);
    }

    /**
     * The daily rhythm the freshness scheme runs on.
     *
     * Early morning, in Lusaka time, and in this order: work out where every
     * listing stands, then tell the sellers who are behind. Reversing them
     * would send a seller a reminder about a state the sweep was about to
     * change.
     */
    private function registerSchedule(): void
    {
        $timezone = (string) config('monafind.display_timezone', 'Africa/Lusaka');

        Schedule::job(new EvaluateStockFreshness)
            ->dailyAt('02:00')
            ->timezone($timezone)
            ->name('inventory:evaluate-stock-freshness')
            ->withoutOverlapping();

        /* Not before people are awake: a 2am SMS is a reason to mute MonaFind. */
        Schedule::job(new SendStockConfirmationReminders)
            ->dailyAt('07:30')
            ->timezone($timezone)
            ->name('inventory:stock-confirmation-reminders')
            ->withoutOverlapping();
    }

    /**
     * Tell Privacy what personal data this module holds.
     *
     * The module owns the answer because the module owns the tables. Privacy
     * orchestrates export and erasure; it never reads these models itself.
     */
    private function registerPersonalData(): void
    {
        $this->app->make(PersonalDataRegistry::class)->register(InventoryPersonalData::class);
    }
}
