<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Jobs\BuildDailyPayoutBatch;
use App\Modules\Payments\Jobs\PollPendingPayments;
use App\Modules\Payments\Jobs\ReconcileGatewayDay;
use App\Modules\Payments\Jobs\RevertRiskySellersToEscrow;
use App\Modules\Payments\Listeners\AlertOnPaymentFailure;
use App\Modules\Payments\Listeners\RaiseRefundForResolvedDispute;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Policies\PayoutBatchPolicy;
use App\Modules\Payments\Policies\RefundPolicy;
use App\Modules\Payments\Services\CollectionService;
use App\Modules\Payments\Services\PaymentModeService;
use App\Modules\Payments\Services\PaymentRecorder;
use App\Modules\Payments\Services\PaymentsConsoleCounters;
use App\Modules\Payments\Services\PayoutService;
use App\Modules\Payments\Services\PlatformCashCheck;
use App\Modules\Payments\Services\ReconciliationService;
use App\Modules\Payments\Services\RefundService;
use App\Modules\Payments\Services\WebhookIngestor;
use App\Modules\Payments\Services\WidgetConfigurator;
use App\Support\Console\ConsoleCounters;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;

/**
 * Payments module — Lenco collections, payouts, refunds and reconciliation.
 *
 * The one place on the platform that talks to a payment gateway, and the
 * layer between the ledger's arithmetic and money actually moving.
 *
 * Three ideas run through everything here.
 *
 * **Nothing is believed because the browser said so.** A collection is settled
 * because the server asked Lenco and Lenco said yes. `onSuccess` in the
 * widget, and a signed webhook body, are both treated as prompts to go and
 * check — never as evidence of an amount or an outcome.
 *
 * **Every confirmation route is idempotent at the database.** The browser's
 * verify call, the webhook and the stuck-payment poller all race each other
 * routinely, and all three write through a unique index on (reference,
 * status). The winner settles the orders; the losers find the row already
 * there and stop. No select-then-insert, because two workers can both pass
 * one.
 *
 * **Money out is never retried automatically.** A transfer that timed out may
 * have paid a seller, so a failed payout line is marked unresolved and left
 * for a person rather than retried into paying twice. This is why
 * ExecutePayoutLine and SendRefund both have `$tries = 1`, alone on the
 * platform.
 */
class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(PaymentRecorder::class);
        $this->app->singleton(WidgetConfigurator::class);
        $this->app->singleton(CollectionService::class);
        $this->app->singleton(WebhookIngestor::class);
        $this->app->singleton(PayoutService::class);
        $this->app->singleton(RefundService::class);
        $this->app->singleton(PaymentModeService::class);
        $this->app->singleton(ReconciliationService::class);
        $this->app->singleton(PlatformCashCheck::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(ConsoleCounters::class)->register(PaymentsConsoleCounters::class);

        $this->registerPolicies();
        $this->registerListeners();
        $this->registerSchedule();
    }

    private function registerPolicies(): void
    {
        Gate::policy(PayoutBatch::class, PayoutBatchPolicy::class);
        Gate::policy(Refund::class, RefundPolicy::class);

        /*
         * Reading reconciliation is Finance's business and nobody else's — a
         * moderator working the dispute queue has no reason to see the
         * platform's cash position.
         */
        Gate::define('view-reconciliation', static fn ($user): bool => $user->hasRole([
            Role::Finance->value,
            Role::PlatformAdmin->value,
        ]));
    }

    /**
     * A resolved dispute has to send the buyer their money.
     *
     * Ledger listens to the same event and posts the accounting; this
     * listener moves the cash. Neither does the other's half — see
     * RefundService for why posting twice is the failure being avoided.
     */
    private function registerListeners(): void
    {
        Event::listen(DisputeResolved::class, RaiseRefundForResolvedDispute::class);

        /*
         * Collections failing in a pattern means MonaFind is broken rather
         * than that a buyer mistyped a PIN. See AlertOnPaymentFailure for how
         * the two are told apart.
         */
        Event::listen(PaymentFailed::class, AlertOnPaymentFailure::class);
    }

    /**
     * Four schedules, at four cadences chosen for four different reasons.
     */
    private function registerSchedule(): void
    {
        /*
         * The safety net under lost webhooks. Every ten minutes, because a
         * buyer whose payment succeeded should not wait an hour to find out —
         * and the sweep is an index scan over a handful of rows.
         */
        Schedule::job(new PollPendingPayments)
            ->everyTenMinutes()
            ->name('payments:poll-pending')
            ->withoutOverlapping();

        /*
         * Prepares a batch and stops. Never approves, never sends — a
         * schedule that could release money would defeat dual control.
         */
        Schedule::job(new BuildDailyPayoutBatch)
            ->dailyAt('06:00')
            ->name('payments:build-payout-batch')
            ->withoutOverlapping();

        /*
         * After midnight, for YESTERDAY: Lenco settles next-day, and
         * reconciling a day still in progress flags every recent collection
         * as unsettled and buries the real exceptions.
         */
        Schedule::job(new ReconcileGatewayDay)
            ->dailyAt('03:30')
            ->name('payments:reconcile')
            ->withoutOverlapping();

        /*
         * Daily. The dispute rate is a trailing measure that moves slowly;
         * checking hourly would only add chances to revert a seller on a
         * rounding wobble.
         */
        Schedule::job(new RevertRiskySellersToEscrow)
            ->dailyAt('04:00')
            ->name('payments:revert-risky-sellers')
            ->withoutOverlapping();
    }
}
