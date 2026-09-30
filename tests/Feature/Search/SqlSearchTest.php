<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Search\Enums\MatchTier;
use App\Modules\Search\Enums\SearchSort;
use App\Modules\Search\Services\FacetPresenter;
use App\Modules\Search\Services\ListingIndexer;
use App\Modules\Search\Services\ProductSearch;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Search\Support\SqlListingRow;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The SQL fallback index, for hosting that cannot run Meilisearch. It has to
 * give the same answers the Meilisearch tests describe, minus typo tolerance,
 * and unlike them it runs everywhere.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    usingSqlSearch();

    $this->category = Category::factory()->create(['name' => 'Brake pads']);
});

/**
 * @param  array<string, mixed>  $product
 * @param  array<string, mixed>  $seller
 */
function sqlListing(string $name, int $priceKwacha = 500, array $product = [], array $seller = []): Product
{
    $shop = Seller::factory()->create([
        'verification_status' => VerificationStatus::Verified,
        ...$seller,
    ]);

    $listing = Product::factory()->ofSeller($shop)->create([
        'name' => $name,
        'category_id' => test()->category->getKey(),
        'inspection_status' => InspectionStatus::Uninspected,
        'freshness_state' => FreshnessState::Fresh,
        ...$product,
    ]);

    $listing->variants()->delete();
    ProductVariant::factory()->for($listing)->default()->create(['price' => kwacha($priceKwacha)]);

    return $listing->refresh();
}

/**
 * @param  array<string, mixed>  $criteria
 * @return array<int, string>
 */
function sqlSearch(array $criteria): array
{
    return resultNames(app(ProductSearch::class)->search(SearchCriteria::fromArray($criteria)));
}

function sqlIndex(): void
{
    app(ListingIndexer::class)->rebuild();
}

it('serves the storefront search page with nothing typed', function (): void {
    sqlListing('Toyota Hilux brake pads');
    sqlListing('Nissan brake pads');
    sqlIndex();

    $this->get(route('search', ['q' => '']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/Search')
            ->has('listings.data', 2));
});

it('puts listings matching every word above those matching only the leading ones', function (): void {
    sqlListing('Toyota Corolla brake pads');
    sqlListing('Toyota Hilux front brake pads');
    /* Holds the later words but not the first, as Meilisearch's "last" strategy drops it. */
    sqlListing('Nissan Hilux brake pads');
    sqlIndex();

    $results = app(ProductSearch::class)->search(SearchCriteria::fromArray(['q' => 'toyota hilux brake pads']));
    $items = $results->listings->items();

    expect(resultNames($results))->toBe(['Toyota Hilux front brake pads', 'Toyota Corolla brake pads'])
        ->and($results->tierFor($items[0]))->toBe(MatchTier::Exact)
        ->and($results->tierFor($items[1]))->toBe(MatchTier::Partial);
});

it('matches whole words, and the last word typed as a prefix', function (): void {
    sqlListing('Alternator');
    sqlListing('Keypad cover');
    sqlListing('Pad cover');
    sqlIndex();

    expect(sqlSearch(['q' => 'alter']))->toBe(['Alternator'])
        ->and(sqlSearch(['q' => 'pad cover']))->toBe(['Pad cover']);
});

it('orders equal matches by quality score, then by price descending', function (): void {
    sqlListing('Brake pads set', 400, [], ['verification_status' => VerificationStatus::UnderReview]);
    sqlListing('Brake pads kit', 200, ['inspection_status' => InspectionStatus::Inspected]);
    sqlListing('Brake pads pack', 900, ['inspection_status' => InspectionStatus::Inspected]);
    sqlIndex();

    expect(sqlSearch(['q' => 'brake pads']))->toBe(['Brake pads pack', 'Brake pads kit', 'Brake pads set']);
});

it('sorts by price in both directions when asked', function (): void {
    sqlListing('Brake pads cheap', 100);
    sqlListing('Brake pads dear', 900);
    sqlIndex();

    expect(sqlSearch(['q' => 'brake pads', 'sort' => SearchSort::PriceAsc->value]))->toBe(['Brake pads cheap', 'Brake pads dear'])
        ->and(sqlSearch(['q' => 'brake pads', 'sort' => SearchSort::PriceDesc->value]))->toBe(['Brake pads dear', 'Brake pads cheap']);
});

it('never shows a listing a buyer could not open', function (): void {
    sqlListing('Brake pads published');
    sqlListing('Brake pads unpublished', 500, ['status' => ListingStatus::Draft, 'published_at' => null]);
    sqlListing('Brake pads hidden', 500, ['freshness_state' => FreshnessState::Hidden]);
    sqlListing('Brake pads suspended shop', 500, [], ['verification_status' => VerificationStatus::Suspended]);
    sqlIndex();

    expect(sqlSearch(['q' => 'brake pads']))->toBe(['Brake pads published']);
});

it('takes a listing out of the index the moment buyers may not see it', function (): void {
    $product = sqlListing('Brake pads');
    sqlIndex();

    $product->update(['freshness_state' => FreshnessState::Hidden]);
    app(ListingIndexer::class)->sync($product->refresh());

    expect(DB::table(SqlListingRow::TABLE)->count())->toBe(0);
});

it('filters a category down through its whole subtree, and by fitment year', function (): void {
    $engine = Category::factory()->create(['name' => 'Engine']);
    $injectors = Category::factory()->childOf($engine)->create(['name' => 'Injectors']);

    sqlListing('Diesel injector old', 500, ['category_id' => $injectors->getKey(), 'year_from' => 1996, 'year_to' => 1999]);
    sqlListing('Diesel injector new', 500, ['category_id' => $injectors->getKey(), 'year_from' => 2008, 'year_to' => 2014]);
    sqlListing('Diesel injector elsewhere');
    sqlIndex();

    expect(sqlSearch(['q' => 'injector', 'category_id' => $engine->getKey()]))->toHaveCount(2)
        ->and(sqlSearch(['q' => 'injector', 'category_id' => $engine->getKey(), 'year' => 2010]))->toBe(['Diesel injector new']);
});

it('keeps only the listings inside a radius, and sorts nearest first only when asked', function (): void {
    $lusaka = ['lat' => -15.4167, 'lng' => 28.2833];
    $kitwe = ['lat' => -12.8024, 'lng' => 28.2132];

    /* The far shop is the stronger listing, so quality alone puts it first. */
    sqlListing('Alternator far', 500, ['inspection_status' => InspectionStatus::Inspected], ['latitude' => $kitwe['lat'], 'longitude' => $kitwe['lng']]);
    sqlListing('Alternator near', 500, [], ['latitude' => $lusaka['lat'], 'longitude' => $lusaka['lng']]);
    sqlListing('Alternator unmapped', 500, [], ['latitude' => null, 'longitude' => null]);
    sqlIndex();

    expect(sqlSearch(['q' => 'alternator', ...$lusaka, 'radius_km' => 50]))->toBe(['Alternator near'])
        ->and(sqlSearch(['q' => 'alternator', ...$lusaka, 'radius_km' => 400]))->toHaveCount(2)
        ->and(sqlSearch(['q' => 'alternator', ...$lusaka, 'sort' => SearchSort::Nearest->value]))
        ->toBe(['Alternator near', 'Alternator far', 'Alternator unmapped'])
        ->and(sqlSearch(['q' => 'alternator', ...$lusaka])[0])->toBe('Alternator far');
});

it('counts every facet value against the current result set', function (): void {
    sqlListing('Alternator breaker', 500, [], ['type' => SellerType::CarBreaker]);
    sqlListing('Alternator shop', 500, ['inspection_status' => InspectionStatus::Inspected], ['type' => SellerType::SparePartsShop]);
    sqlListing('Wiper blade', 500, ['inspection_status' => InspectionStatus::Inspected]);
    sqlIndex();

    $results = app(ProductSearch::class)->search(SearchCriteria::fromArray(['q' => 'alternator']));
    $facets = app(FacetPresenter::class)->present($results->facets);

    expect($facets['inspected'])->toBe(1)
        ->and($facets['verified'])->toBe(2)
        ->and(collect($facets['seller_types'])->pluck('count', 'value')->all())
        ->toBe([SellerType::SparePartsShop->value => 1, SellerType::CarBreaker->value => 1]);
});

it('falls back to the closest category when nothing matches', function (): void {
    sqlListing('Ceramic disc set');
    sqlIndex();

    $results = app(ProductSearch::class)->search(SearchCriteria::fromArray(['q' => 'hilux brake pads 2012']));

    expect($results->tier)->toBe(MatchTier::Related)
        ->and(resultNames($results))->toBe(['Ceramic disc set']);
});
