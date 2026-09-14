<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Events\ListingInspectionChanged;
use App\Modules\Catalog\Events\ListingStatusChanged;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Services\FreshnessService;
use App\Modules\Search\Jobs\RebuildSearchIndex;
use App\Modules\Search\Jobs\ReindexListing;
use App\Modules\Search\Jobs\ReindexSellerListings;
use App\Modules\Search\Services\ListingIndexer;
use App\Modules\Search\Services\ProductDocument;
use App\Modules\Search\Support\ProductIndex;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Events\SellerVerificationChanged;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->seed(SettingsSeeder::class));

it('describes a listing with everything the facets filter on', function (): void {
    $seller = Seller::factory()->create(['latitude' => -15.4167, 'longitude' => 28.2833]);
    $product = Product::factory()->ofSeller($seller)->create([
        'inspection_status' => InspectionStatus::Inspected,
        'year_from' => 2008,
        'year_to' => 2010,
    ]);

    $document = app(ProductDocument::class)->for($product->fresh(ProductDocument::relations()));

    expect($document)
        ->toHaveKeys(ProductIndex::filterableAttributes())
        ->and($document['inspected'])->toBeTrue()
        ->and($document['seller_verified'])->toBeTrue()
        ->and($document['years'])->toBe([2008, 2009, 2010])
        ->and($document['_geo'])->toBe(['lat' => -15.4167, 'lng' => 28.2833])
        ->and($document['quality_score'])->toBeFloat();
});

it('leaves _geo off a shop that was never placed on the map', function (): void {
    $seller = Seller::factory()->create(['latitude' => null, 'longitude' => null]);
    $product = Product::factory()->ofSeller($seller)->create();

    expect(app(ProductDocument::class)->for($product->fresh(ProductDocument::relations())))
        ->not->toHaveKey('_geo');
});

it('files a listing under every heading above it', function (): void {
    $engine = Category::factory()->create(['name' => 'Engine']);
    $injectors = Category::factory()->childOf($engine)->create(['name' => 'Injectors']);
    $product = Product::factory()->create(['category_id' => $injectors->getKey()]);

    $document = app(ProductDocument::class)->for($product->fresh(ProductDocument::relations()));

    expect($document['category_ids'])->toBe([$engine->getKey(), $injectors->getKey()])
        ->and($document['category_path'])->toBe('Engine / Injectors');
});

/*
 * The bulk paths are where the index rots. Everything below covers a change
 * Scout's own model observer never sees.
 */
it('re-indexes a listing when the freshness sweep moves it', function (): void {
    Queue::fake();

    $product = Product::factory()->create(['freshness_confirmed_at' => now()->subDays(10)]);

    app(FreshnessService::class)->refreshStates();

    Queue::assertPushed(
        ReindexListing::class,
        fn (ReindexListing $job): bool => $job->product->is($product),
    );
});

it('re-indexes a listing when a moderator changes its inspection badge', function (): void {
    Queue::fake();

    $product = Product::factory()->create();

    event(new ListingInspectionChanged(
        $product,
        InspectionStatus::Uninspected,
        InspectionStatus::Inspected,
        User::factory()->create(),
    ));

    Queue::assertPushed(ReindexListing::class);
});

it('re-indexes a listing when it leaves the storefront', function (): void {
    Queue::fake();

    $product = Product::factory()->create();

    event(new ListingStatusChanged($product, ListingStatus::Published, ListingStatus::Unpublished));

    Queue::assertPushed(ReindexListing::class);
});

it('re-indexes a whole shop when its verification changes', function (): void {
    Queue::fake();

    $seller = Seller::factory()->create();

    event(new SellerVerificationChanged($seller, VerificationStatus::UnderReview, VerificationStatus::Verified));

    Queue::assertPushed(
        ReindexSellerListings::class,
        fn (ReindexSellerListings $job): bool => $job->seller->is($seller),
    );
});

it('rebuilds the index nightly without ever emptying it', function (): void {
    usingMeilisearch();

    Product::factory()->count(3)->create();
    Product::factory()->create(['status' => ListingStatus::Draft, 'published_at' => null]);
    Product::factory()->create(['freshness_state' => FreshnessState::Hidden]);

    $counts = app(ListingIndexer::class)->rebuild();
    meilisearchSettled();

    expect($counts['indexed'])->toBe(3)
        ->and($counts['removed'])->toBe(2);
});

it('takes a listing out of the index the moment buyers may not see it', function (): void {
    usingMeilisearch();

    $product = Product::factory()->create();
    indexListings();

    $product->update(['freshness_state' => FreshnessState::Hidden]);
    app(ListingIndexer::class)->sync($product->refresh());
    meilisearchSettled();

    expect(Product::search('')->paginate(10)->total())->toBe(0);
});

it('runs the rebuild from the command line', function (): void {
    usingMeilisearch();

    Product::factory()->count(2)->create();

    $this->artisan('search:reindex')
        ->expectsOutputToContain('2 listing(s) indexed')
        ->assertSuccessful();
});

it('schedules the nightly rebuild after the freshness sweep', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->keyBy(fn ($event) => $event->description ?? '');

    expect($events)->toHaveKey('search:rebuild-index')
        ->and($events['search:rebuild-index']->expression)->toBe('30 2 * * *');
});

it('dispatches the rebuild job onto the search queue', function (): void {
    expect((new RebuildSearchIndex)->queue)->toBe('search');
});
