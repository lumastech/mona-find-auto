<?php

declare(strict_types=1);

namespace App\Modules\Ledger;

use App\Modules\Ledger\Jobs\ReleaseSellerReserves;
use App\Modules\Ledger\Listeners\PostDisputeResolution;
use App\Modules\Ledger\Listeners\PostOrderPayment;
use App\Modules\Ledger\Listeners\ReleaseEscrowForCompletedOrder;
use App\Modules\Ledger\Models\LedgerAdjustment;
use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Modules\Ledger\Policies\LedgerAdjustmentPolicy;
use App\Modules\Ledger\Policies\MonetisationPolicyPolicy;
use App\Modules\Ledger\Services\CommissionInvoiceService;
use App\Modules\Ledger\Services\LedgerAdjustmentService;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\LedgerMonetisationPolicyProvider;
use App\Modules\Ledger\Services\LedgerService;
use App\Modules\Ledger\Services\MonetisationPolicyService;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Ledger\Services\PolicyCalculator;
use App\Modules\Orders\Contracts\MonetisationPolicyProvider;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Events\OrderCompleted;
use App\Modules\Orders\Events\OrderPaid;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;

/**
 * Ledger module — the double-entry ledger and the terms it posts by.
 *
 * Built before Lenco on purpose. Every money path on the platform — escrow,
 * direct settlement, refunds, clawbacks, payouts, reserves — is decided here,
 * in code that has never heard of a payment gateway, which is what lets all
 * of it be tested offline and in full before the first API call exists.
 *
 * Three ideas hold it together.
 *
 * Nothing writes to the ledger except LedgerService::post(). The models
 * refuse to be created outside a posting, so an entry cannot be inserted that
 * skipped the balance check, skipped its idempotency key, or never reached
 * the materialised balances — and being append-only, such an entry could
 * never afterwards be corrected.
 *
 * Every posting names the business event it records rather than the moment it
 * ran. Webhooks arrive twice, queued listeners are retried, operators click
 * twice; all of those resolve to one key and post once.
 *
 * And no posting ever reads a seller's live terms. The commission, the
 * payment mode and the windows were frozen onto the order when the money
 * arrived, and every figure from that point on — including a refund worked
 * out months later — comes from that frozen copy. This module is what makes
 * that snapshot mean something, by binding the provider Orders declared and
 * has so far been answering with platform defaults.
 */
class LedgerServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        /*
         * Orders declared this interface and shipped a stub returning the
         * platform settings for everybody. Per-seller policies exist now, so
         * this binding replaces it — and because Ledger is registered after
         * Orders in config/modules.php, it is this one that wins. Nothing in
         * checkout or the state machine changes.
         */
        $this->app->bind(MonetisationPolicyProvider::class, LedgerMonetisationPolicyProvider::class);

        $this->app->singleton(LedgerBalances::class);
        $this->app->singleton(LedgerService::class);
        $this->app->singleton(PolicyCalculator::class);
        $this->app->singleton(CommissionInvoiceService::class);
        $this->app->singleton(OrderPostingService::class);
        $this->app->singleton(MonetisationPolicyService::class);
        $this->app->singleton(LedgerAdjustmentService::class);
    }

    protected function bootModule(): void
    {
        $this->registerPolicies();
        $this->registerListeners();
        $this->registerSchedule();
    }

    private function registerPolicies(): void
    {
        Gate::policy(LedgerAdjustment::class, LedgerAdjustmentPolicy::class);
        Gate::policy(MonetisationPolicy::class, MonetisationPolicyPolicy::class);

        /*
         * Reading the ledger is not a permission on any one model — an entry,
         * a balance and an invoice are all the same question — so it is an
         * ability rather than a policy. Finance and platform administrators
         * only: a moderator works the dispute queue and has no business
         * reading the platform's revenue.
         */
        Gate::define('view-ledger', static fn ($user): bool => $user->hasRole([
            Role::Finance->value,
            Role::PlatformAdmin->value,
        ]));
    }

    /**
     * The three moments money moves because of something an order did.
     *
     * Unlike the notification listeners elsewhere on the platform, these
     * carry a guarantee rather than news — so a backed-up queue delays the
     * ledger, and the ledger has to be able to survive that. It can: each
     * posting is idempotent and each reads the order's frozen snapshot, so a
     * job that runs an hour late posts exactly what it would have posted an
     * hour ago.
     */
    private function registerListeners(): void
    {
        Event::listen(OrderPaid::class, PostOrderPayment::class);
        Event::listen(OrderCompleted::class, ReleaseEscrowForCompletedOrder::class);
        Event::listen(DisputeResolved::class, PostDisputeResolution::class);
    }

    /**
     * Daily, not hourly.
     *
     * The reserve covers disputes nobody has raised yet, and the sweeps that
     * decide them run hourly; trimming the reserve on the same cadence would
     * hand back money the platform is about to need.
     */
    private function registerSchedule(): void
    {
        Schedule::job(new ReleaseSellerReserves)
            ->dailyAt('02:30')
            ->name('ledger:release-seller-reserves')
            ->withoutOverlapping();
    }
}
