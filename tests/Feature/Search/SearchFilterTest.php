<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\City;
use App\Modules\Search\Enums\SearchSort;
use App\Modules\Search\Services\FacetPresenter;
use App\Modules\Search\Services\ProductSearch;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;

/* Two real places, roughly 290km apart. */
const LUSAKA = ['lat' => -15.4167, 'lng' => 28.2833];

const KITWE = ['lat' => -12.8024, 'lng' => 28.2132];

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    usingMeilisearch();

    $this->search = app(ProductSearch::class);
});

function shopAt(array $point, array $attributes = []): Seller
{
    return Seller::factory()->create([
        'latitude' => $point['lat'],
        'longitude' => $point['lng'],
        ...$attributes,
    ]);
}

function named(string $name, Seller $seller, array $attributes = [], int $priceKwacha = 500): Product
{
    $listing = Product::factory()->ofSeller($seller)->create(['name' => $name, ...$attributes]);

    $listing->variants()->delete();
    ProductVariant::factory()->for($listing)->default()->create(['price' => kwacha($priceKwacha)]);

    return $listing->refresh();
}

function filtered(array $criteria): array
{
    return resultNames(test()->search->search(SearchCriteria::fromArray($criteria)));
}

it('keeps only the listings inside a radius of the buyer', function (): void {
    named('Alternator near', shopAt(LUSAKA));
    named('Alternator far', shopAt(KITWE));

    indexListings();

    expect(filtered(['q' => 'alternator', ...LUSAKA, 'radius_km' => 50]))
        ->toBe(['Alternator near'])
        ->and(filtered(['q' => 'alternator', ...LUSAKA, 'radius_km' => 400]))
        ->toHaveCount(2);
});

it('drops a shop that has never been placed on the map out of a radius search', function (): void {
    named('Alternator near', shopAt(LUSAKA));
    named('Alternator unmapped', Seller::factory()->create(['latitude' => null, 'longitude' => null]));

    indexListings();

    expect(filtered(['q' => 'alternator', ...LUSAKA, 'radius_km' => 50]))->toBe(['Alternator near']);
});

it('sorts by distance only when the buyer asked for it and told us where they are', function (): void {
    /* The far shop is the stronger listing, so quality alone would put it first. */
    named('Alternator far', shopAt(KITWE), ['inspection_status' => InspectionStatus::Inspected]);
    named('Alternator near', shopAt(LUSAKA));

    indexListings();

    expect(filtered(['q' => 'alternator', ...LUSAKA, 'sort' => SearchSort::Nearest->value]))
        ->toBe(['Alternator near', 'Alternator far'])
        /* Same sort, no location: the default order, not a guess. */
        ->and(filtered(['q' => 'alternator', 'sort' => SearchSort::Nearest->value]))
        ->toBe(['Alternator far', 'Alternator near']);
});

it('never lets distance into the default order', function (): void {
    named('Alternator far', shopAt(KITWE), ['inspection_status' => InspectionStatus::Inspected]);
    named('Alternator near', shopAt(LUSAKA));

    indexListings();

    expect(filtered(['q' => 'alternator', ...LUSAKA]))->toBe(['Alternator far', 'Alternator near']);
});

it('filters on each facet the sidebar offers', function (): void {
    $make = Make::factory()->create(['name' => 'Toyota']);

    /* Both in one town, so the province facet has something to prove. */
    $city = City::factory()->create();
    $breaker = shopAt(LUSAKA, ['type' => SellerType::CarBreaker, 'province_id' => $city->province_id, 'city_id' => $city->getKey()]);
    $shop = shopAt(LUSAKA, ['type' => SellerType::SparePartsShop, 'province_id' => $city->province_id, 'city_id' => $city->getKey()]);

    /* Pinned rather than left to the factory's dice: every field below is one this test filters on. */
    named('Alternator breaker', $breaker, [
        'make_id' => $make->getKey(),
        'delivery_available' => false,
        'year_from' => 1996,
        'year_to' => 1999,
    ]);
    named('Alternator shop', $shop, [
        'condition' => Condition::BrandNew,
        'inspection_status' => InspectionStatus::Inspected,
        'delivery_available' => true,
        'year_from' => 2008,
        'year_to' => 2014,
    ], priceKwacha: 900);

    indexListings();

    expect(filtered(['q' => 'alternator', 'seller_type' => SellerType::CarBreaker->value]))->toBe(['Alternator breaker'])
        ->and(filtered(['q' => 'alternator', 'condition' => Condition::BrandNew->value]))->toBe(['Alternator shop'])
        ->and(filtered(['q' => 'alternator', 'inspected' => true]))->toBe(['Alternator shop'])
        ->and(filtered(['q' => 'alternator', 'delivery' => true]))->toBe(['Alternator shop'])
        ->and(filtered(['q' => 'alternator', 'make_id' => $make->getKey()]))->toBe(['Alternator breaker'])
        ->and(filtered(['q' => 'alternator', 'year' => 2010]))->toBe(['Alternator shop'])
        ->and(filtered(['q' => 'alternator', 'min_price' => kwacha(800)->ngwee]))->toBe(['Alternator shop'])
        ->and(filtered(['q' => 'alternator', 'max_price' => kwacha(800)->ngwee]))->toBe(['Alternator breaker'])
        ->and(filtered(['q' => 'alternator', 'province_id' => $city->province_id]))->toHaveCount(2)
        ->and(filtered(['q' => 'alternator', 'city_id' => $city->getKey()]))->toHaveCount(2);
});

/**
 * Filtering a heading has to include everything under it — the same promise
 * the category pages make, and the reason every ancestor is indexed.
 */
it('filters a category down through its whole subtree', function (): void {
    $engine = Category::factory()->create(['name' => 'Engine']);
    $injectors = Category::factory()->childOf($engine)->create(['name' => 'Injectors']);

    named('Diesel injector', shopAt(LUSAKA), ['category_id' => $injectors->getKey()]);

    indexListings();

    expect(filtered(['q' => 'injector', 'category_id' => $engine->getKey()]))->toBe(['Diesel injector']);
});

it('counts every facet value against the current result set', function (): void {
    named('Alternator breaker', shopAt(LUSAKA, ['type' => SellerType::CarBreaker]));
    named('Alternator shop', shopAt(LUSAKA, ['type' => SellerType::SparePartsShop]), [
        'inspection_status' => InspectionStatus::Inspected,
    ]);

    indexListings();

    $results = $this->search->search(SearchCriteria::fromArray(['q' => 'alternator']));
    $facets = app(FacetPresenter::class)->present($results->facets);

    expect($facets['inspected'])->toBe(1)
        ->and($facets['verified'])->toBe(2)
        ->and(collect($facets['seller_types'])->pluck('count', 'value')->all())
        ->toBe([SellerType::SparePartsShop->value => 1, SellerType::CarBreaker->value => 1]);
});
