<?php

declare(strict_types=1);

namespace App\Modules\Search;

use App\Modules\Catalog\Events\ListingInspectionChanged;
use App\Modules\Catalog\Events\ListingStatusChanged;
use App\Modules\Inventory\Events\ProductFreshnessChanged;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Modules\Ratings\Events\SellerTrustScoreChanged;
use App\Modules\Search\Console\RebuildSearchIndexCommand;
use App\Modules\Search\Contracts\ListingSearch;
use App\Modules\Search\Contracts\SellerReputationProvider;
use App\Modules\Search\Jobs\RebuildSearchIndex;
use App\Modules\Search\Listeners\ReindexOnFreshnessChange;
use App\Modules\Search\Listeners\ReindexOnInspectionChange;
use App\Modules\Search\Listeners\ReindexOnListingStatusChange;
use App\Modules\Search\Listeners\ReindexOnSellerTrustChange;
use App\Modules\Search\Listeners\ReindexOnSellerVerificationChange;
use App\Modules\Search\Privacy\SearchPersonalData;
use App\Modules\Search\Services\MeilisearchListingSearch;
use App\Modules\Search\Services\ProductDocument;
use App\Modules\Search\Services\SqlListingSearch;
use App\Modules\Search\Support\NeutralSellerReputation;
use App\Modules\Search\Support\SqlSearchEngine;
use App\Modules\Sellers\Events\SellerVerificationChanged;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Laravel\Scout\EngineManager;

/**
 * Search module — how a buyer finds a part.
 *
 * The module owns one index of published listings and the ordering they come
 * back in — Meilisearch, or the `search_listings` table when `scout.driver`
 * is `sql` on hosting that cannot run Meilisearch. That ordering is the platform's most
 * consequential product decision: it decides which Zambian shop gets the
 * call, and it is therefore configured rather than coded — the weights behind
 * every listing's quality score are `ranking.weight.*` in settings, and an
 * administrator moving one of them re-ranks the catalogue.
 *
 * What is deliberately absent: distance. Nearest-first exists as a sort a
 * buyer may choose, and a radius exists as a filter, but neither leaks into
 * the default order. See the README beside this file for the ranking rules
 * and why they are in the order they are.
 *
 * Its couplings are four domain events it listens to and one trait Catalog's
 * Product uses. It calls into nothing.
 */
class SearchServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        /*
         * Half the quality score is other people's experience of a seller,
         * and that history belongs to Ratings. This binding is the floor:
         * with Ratings disabled, every seller scores the same on rating,
         * review count and disputes, which is the correct answer rather than
         * a placeholder — with no reviews anywhere, nobody has earned a place
         * above anybody else. RatingsServiceProvider binds over it.
         */
        $this->app->bind(SellerReputationProvider::class, NeutralSellerReputation::class);

        /* Memoises reputations across a chunk of listings; one per process. */
        $this->app->singleton(ProductDocument::class);

        /*
         * Meilisearch unless the platform is running the SQL fallback. Read
         * per resolution rather than once, so a test that switches driver
         * gets the matching query side.
         */
        $this->app->bind(ListingSearch::class, fn (Application $app): ListingSearch => config('scout.driver') === SqlSearchEngine::DRIVER
            ? $app->make(SqlListingSearch::class)
            : $app->make(MeilisearchListingSearch::class));

        $this->callAfterResolving(EngineManager::class, function (EngineManager $engines): void {
            $engines->extend(SqlSearchEngine::DRIVER, fn (Application $app): SqlSearchEngine => $app->make(SqlSearchEngine::class));
        });
    }

    protected function bootModule(): void
    {
        $this->registerPersonalData();
        $this->registerListeners();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildSearchIndexCommand::class]);
        }
    }

    /**
     * The four facts that change a listing's place in the index without ever
     * touching the listing's own row.
     *
     * Scout's model observer covers ordinary saves. It does not cover
     * Inventory's nightly freshness sweep, which updates in bulk, it does
     * not cover a seller being verified or suspended, which changes every
     * document that seller owns, and it does not cover a buyer reviewing a
     * shop, which changes the quality score on every part the shop lists.
     * Those are what these listeners are for.
     */
    private function registerListeners(): void
    {
        Event::listen(ProductFreshnessChanged::class, ReindexOnFreshnessChange::class);
        Event::listen(ListingInspectionChanged::class, ReindexOnInspectionChange::class);
        Event::listen(ListingStatusChanged::class, ReindexOnListingStatusChange::class);
        Event::listen(SellerVerificationChanged::class, ReindexOnSellerVerificationChange::class);
        Event::listen(SellerTrustScoreChanged::class, ReindexOnSellerTrustChange::class);
    }

    /**
     * The nightly rebuild.
     *
     * After Inventory's 02:00 freshness sweep, so the scores it writes are
     * computed against the states that sweep just assigned rather than
     * yesterday's — otherwise every listing that slid from Fresh to Ageing
     * overnight would keep its old ranking for a further day.
     */
    private function registerSchedule(): void
    {
        Schedule::job(new RebuildSearchIndex)
            ->dailyAt('02:30')
            ->timezone((string) config('monafind.display_timezone', 'Africa/Lusaka'))
            ->name('search:rebuild-index')
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
        $this->app->make(PersonalDataRegistry::class)->register(SearchPersonalData::class);
    }
}
