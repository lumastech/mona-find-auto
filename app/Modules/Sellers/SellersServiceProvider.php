<?php

declare(strict_types=1);

namespace App\Modules\Sellers;

use App\Modules\Sellers\Events\SellerVerificationChanged;
use App\Modules\Sellers\Listeners\NotifySellerOfVerification;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Policies\SellerAccessPolicy;
use App\Modules\Sellers\Services\SellerConsoleCounters;
use App\Modules\Sellers\Services\SellerConsoleStatistics;
use App\Support\Console\ConsoleCounters;
use App\Support\Console\ConsoleStatistics;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Reference\ReferenceRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Sellers module — seller onboarding, verification, monetisation policy and payment mode.
 *
 * Everything about *being a business on MonaFind* lives here: the sign-up
 * wizard, the versioned policies buyers accept at checkout, the encrypted
 * payout accounts, and the verification workflow that grants the badge.
 *
 * One policy class governs the whole module — the seller's policies, payout
 * accounts and documents all answer the same question as the Seller row
 * itself: is this your business, or are you staff?
 */
class SellersServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        /*
         * What this module contributes to the staff console: its own queue
         * count, and the columns of its own tables that point at somebody
         * else's reference lists. Both are declarations rather than calls —
         * Admin reads them, and never reaches in here to count or to merge.
         */
        $this->app->make(ConsoleCounters::class)->register(SellerConsoleCounters::class);
        $this->app->make(ConsoleStatistics::class)->register(SellerConsoleStatistics::class);

        $references = $this->app->make(ReferenceRegistry::class);
        $references->link('provinces', 'sellers', 'province_id');
        $references->link('cities', 'sellers', 'city_id');

        Gate::policy(Seller::class, SellerAccessPolicy::class);

        /*
         * The answer the shop has been waiting days for. Hung off the event
         * rather than written into the verification service, because the same
         * decision is reached from the admin console and from the automatic
         * suspension sweep.
         */
        Event::listen(SellerVerificationChanged::class, NotifySellerOfVerification::class);
    }
}
