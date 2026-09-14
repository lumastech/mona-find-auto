<?php

declare(strict_types=1);

namespace App\Modules\Ratings;

use App\Modules\Orders\Events\DisputeOpened;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Events\OrderCompleted;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Modules\Ratings\Events\RatingModerated;
use App\Modules\Ratings\Events\RatingSubmitted;
use App\Modules\Ratings\Jobs\RecomputeAllTrustScores;
use App\Modules\Ratings\Listeners\InviteRatingsOnOrderCompleted;
use App\Modules\Ratings\Listeners\NotifyRateeOfRating;
use App\Modules\Ratings\Listeners\RecomputeTrustOnDisputeChange;
use App\Modules\Ratings\Listeners\RecomputeTrustOnRatingChange;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Policies\RatingPolicy;
use App\Modules\Ratings\Privacy\RatingPersonalData;
use App\Modules\Ratings\Services\OrderRatingSource;
use App\Modules\Ratings\Services\RatingConsoleCounters;
use App\Modules\Ratings\Services\RatingEligibility;
use App\Modules\Ratings\Services\RatingModerationService;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Ratings\Services\RatingSourceRegistry;
use App\Modules\Ratings\Services\RatingsSellerReputation;
use App\Modules\Ratings\Services\TrustScoreService;
use App\Modules\Search\Contracts\SellerReputationProvider;
use App\Support\Console\ConsoleCounters;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;

/**
 * Ratings module — reviews, replies, moderation and trust.
 *
 * Three ideas hold it together.
 *
 * A rating is earned, never volunteered. Every one is attached to a completed
 * order or an endorsement, and the database enforces one per direction per
 * source. What counts as "completed" and who was involved is not decided
 * here: each kind of rateable thing ships a RatingSourceResolver and
 * registers it, which is how the Mechanics module will add endorsements
 * without a line of this module changing.
 *
 * Half the directions are private. A buyer's review of a shop is the
 * platform's most-read content; a shop's rating of a buyer is trade
 * information, shown to sellers, mechanics and staff and to nobody else —
 * least of all the buyer, who would otherwise learn to trade a bad review for
 * a good rating. That rule lives on RatingDirection and is applied in the
 * query, not in the template.
 *
 * And a seller's reputation is a number that other modules read. It is
 * precomputed into seller_trust_scores because the nightly search re-index
 * asks about every seller at once, it is rebuilt in full each night because a
 * trailing dispute window moves on its own, and when it changes it fires one
 * event — which is the whole of the coupling with Search.
 */
class RatingsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(RatingEligibility::class);
        $this->app->singleton(RatingService::class);
        $this->app->singleton(RatingModerationService::class);
        $this->app->singleton(TrustScoreService::class);

        /*
         * One registry per process, seeded with the one rateable thing this
         * module knows about. Mechanics adds endorsements to it from its own
         * provider.
         */
        $this->app->singleton(RatingSourceRegistry::class, function (): RatingSourceRegistry {
            $registry = new RatingSourceRegistry;
            $registry->register(new OrderRatingSource);

            return $registry;
        });

        /*
         * Search shipped a neutral implementation so that ranking worked
         * before anybody had been reviewed. Now that reviews exist, this
         * answers instead — and it is the entire surface between the two
         * modules, alongside the one event below.
         */
        $this->app->bind(SellerReputationProvider::class, RatingsSellerReputation::class);
    }

    protected function bootModule(): void
    {
        $this->registerPersonalData();
        $this->app->make(ConsoleCounters::class)->register(RatingConsoleCounters::class);

        Gate::policy(Rating::class, RatingPolicy::class);

        $this->registerListeners();
        $this->registerSchedule();
    }

    /**
     * Two listeners carry news and two keep a derived number in step.
     *
     * Nothing that has to be true for the platform to be correct happens in
     * any of them. Eligibility is re-checked against the order's status when
     * somebody actually submits, and the trust table is rebuilt nightly, so a
     * backed-up queue costs freshness rather than a guarantee.
     */
    private function registerListeners(): void
    {
        Event::listen(OrderCompleted::class, InviteRatingsOnOrderCompleted::class);

        Event::listen(RatingSubmitted::class, NotifyRateeOfRating::class);
        Event::listen(RatingSubmitted::class, RecomputeTrustOnRatingChange::class);
        Event::listen(RatingModerated::class, RecomputeTrustOnRatingChange::class);

        Event::listen(DisputeOpened::class, RecomputeTrustOnDisputeChange::class);
        Event::listen(DisputeResolved::class, RecomputeTrustOnDisputeChange::class);
    }

    /**
     * The nightly rebuild, at 02:15.
     *
     * Between Inventory's freshness sweep at 02:00 and Search's index rebuild
     * at 02:30, so the scores that rebuild reads were written minutes earlier
     * rather than a day earlier.
     */
    private function registerSchedule(): void
    {
        Schedule::job(new RecomputeAllTrustScores)
            ->dailyAt('02:15')
            ->timezone((string) config('monafind.display_timezone', 'Africa/Lusaka'))
            ->name('ratings:recompute-trust-scores')
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
        $this->app->make(PersonalDataRegistry::class)->register(RatingPersonalData::class);
    }
}
