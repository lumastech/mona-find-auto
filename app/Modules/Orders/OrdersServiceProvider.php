<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Modules\Orders\Contracts\DeliveryFeeStrategy;
use App\Modules\Orders\Contracts\MonetisationPolicyProvider;
use App\Modules\Orders\Contracts\VatRateProvider;
use App\Modules\Orders\Events\DisputeOpened;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Events\OrderStateChanged;
use App\Modules\Orders\Jobs\AutoCancelUnconfirmedOrders;
use App\Modules\Orders\Jobs\AutoCompleteFulfilledOrders;
use App\Modules\Orders\Listeners\NotifyPartiesOfDisputeDecision;
use App\Modules\Orders\Listeners\NotifyPartiesOfOrderState;
use App\Modules\Orders\Listeners\NotifySellerOfDispute;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Orders\Policies\OrderDisputePolicy;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Modules\Orders\Privacy\OrderErasureBlocker;
use App\Modules\Orders\Privacy\OrderPersonalData;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Services\DisputeService;
use App\Modules\Orders\Services\FlatRateDeliveryFee;
use App\Modules\Orders\Services\OrderConsoleCounters;
use App\Modules\Orders\Services\OrderDocumentService;
use App\Modules\Orders\Services\OrderPaymentService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Services\SettingsMonetisationPolicyProvider;
use App\Modules\Orders\Services\SettingsVatRateProvider;
use App\Modules\Privacy\Services\ErasureGuard;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Support\Console\ConsoleCounters;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;

/**
 * Orders module — checkout, fulfilment, timers and disputes.
 *
 * Everything between a full cart and a settled sale. Two ideas hold it
 * together and both are about time passing.
 *
 * The first is the snapshot. An order is a contract, and the terms it is
 * settled under — the seller's commission, their payment mode, the escrow
 * windows — are frozen onto the row when the money arrives and never read
 * from their sources again. Staff change all three regularly; orders already
 * running must not notice.
 *
 * The second is that somebody always stops answering. Sellers do not confirm;
 * buyers collect a part, fit it, and never open the app again. Two scheduled
 * sweeps decide those cases, and the deadlines they run against are stored on
 * each order rather than computed, so a setting changed today cannot move a
 * deadline an order is already running against.
 *
 * Payments is not built yet. The seam is deliberate and narrow:
 * OrderPaymentService::settle() takes a group whose collection has cleared
 * and does everything on this side of the line. MonetisationPolicyProvider is
 * the other half of that seam, and Ledger has since bound it — per-seller
 * terms arrived without a line of this module changing.
 */
class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        /*
         * Delivery pricing is one flat rate per shop in Release 1. Per-zone
         * and distance-based pricing arrive as further implementations of
         * this interface rather than as branches inside checkout.
         */
        $this->app->bind(DeliveryFeeStrategy::class, FlatRateDeliveryFee::class);

        /*
         * Commercial terms belong to Ledger, which binds its own
         * implementation over this one. This binding is the floor: with
         * Ledger disabled, every seller trades on the platform defaults —
         * and because those get snapshotted onto real orders, it has to
         * return the real numbers rather than zeroes.
         */
        $this->app->bind(MonetisationPolicyProvider::class, SettingsMonetisationPolicyProvider::class);

        /*
         * The same arrangement for the VAT rate, which Finance keeps as a
         * dated schedule. With Finance disabled the flat setting answers for
         * every date — wrong about history, but never wrong about today.
         */
        $this->app->bind(VatRateProvider::class, SettingsVatRateProvider::class);

        $this->app->singleton(OrderStateMachine::class);
        $this->app->singleton(CheckoutService::class);
        $this->app->singleton(DisputeService::class);
        $this->app->singleton(OrderPaymentService::class);
        $this->app->singleton(OrderDocumentService::class);
    }

    protected function bootModule(): void
    {
        $this->registerPersonalData();
        $this->app->make(ConsoleCounters::class)->register(OrderConsoleCounters::class);

        $this->registerPolicies();
        $this->registerListeners();
        $this->registerSchedule();
    }

    private function registerPolicies(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(OrderDispute::class, OrderDisputePolicy::class);
    }

    /**
     * Both listeners only carry news.
     *
     * Nothing that has to be true for the platform to be correct happens in a
     * listener: stock comes down inside Inventory's own idempotent ledger,
     * and a dispute's hold on auto-completion is written in the same
     * transaction as the dispute. A backed-up queue delays a message, never a
     * guarantee.
     */
    private function registerListeners(): void
    {
        Event::listen(OrderStateChanged::class, NotifyPartiesOfOrderState::class);
        Event::listen(DisputeOpened::class, NotifySellerOfDispute::class);
        Event::listen(DisputeResolved::class, NotifyPartiesOfDisputeDecision::class);
    }

    /**
     * The two sweeps that decide the cases nobody came back for.
     *
     * Hourly rather than daily, and that is a deliberate difference from the
     * daily jobs elsewhere in the application. These two hold somebody's
     * money: a seller waiting on an escrow release should wait hours past the
     * window at worst, and a buyer whose seller never answered should not sit
     * a further day past the deadline before being refunded.
     */
    private function registerSchedule(): void
    {
        Schedule::job(new AutoCancelUnconfirmedOrders)
            ->hourly()
            ->name('orders:auto-cancel-unconfirmed')
            ->withoutOverlapping();

        Schedule::job(new AutoCompleteFulfilledOrders)
            ->hourly()
            ->name('orders:auto-complete-fulfilled')
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
        $this->app->make(PersonalDataRegistry::class)->register(OrderPersonalData::class);

        /*
         * And the reason an erasure sometimes has to wait. See
         * OrderErasureBlocker for what counts as "in flight".
         */
        $this->app->make(ErasureGuard::class)->register(OrderErasureBlocker::class);
    }
}
