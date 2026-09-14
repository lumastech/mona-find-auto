<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Enums\SearchSort;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    usingMeilisearch();

    $this->category = Category::factory()->create(['name' => 'Alternators']);
    $this->seller = Seller::factory()->create();
});

function published(string $name, array $attributes = []): Product
{
    return Product::factory()->ofSeller(test()->seller)->create([
        'name' => $name,
        'category_id' => test()->category->getKey(),
        ...$attributes,
    ]);
}

it('lets a guest search without an account', function (): void {
    published('Toyota alternator');
    indexListings();

    $this->get(route('search', ['q' => 'alternator']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/Search')
            ->where('term', 'alternator')
            ->has('listings.data', 1)
            ->where('listings.data.0.name', 'Toyota alternator'));
});

it('carries both listing badges and the match tier onto every result', function (): void {
    published('Toyota alternator', ['inspection_status' => InspectionStatus::Inspected]);
    indexListings();

    $this->get(route('search', ['q' => 'alternator']))
        ->assertInertia(fn ($page) => $page
            /* Condition and inspection are independent and always travel together. */
            ->has('listings.data.0.condition')
            ->has('listings.data.0.inspection')
            ->where('listings.data.0.inspection.inspected', true)
            ->where('listings.data.0.match.tier', 'exact')
            ->has('listings.data.0.match.explanation'));
});

it('hands the page its facet counts and the sorts a buyer may choose', function (): void {
    published('Toyota alternator');
    indexListings();

    $this->get(route('search', ['q' => 'alternator']))
        ->assertInertia(fn ($page) => $page
            ->where('facets.verified', 1)
            ->has('facets.conditions')
            ->has('facets.makes')
            ->where('sort', SearchSort::Recommended->value)
            ->has('sortOptions', count(SearchSort::cases()))
            ->where('maxRadiusKm', 500));
});

it('echoes the applied filters back so the page can draw its chips', function (): void {
    published('Toyota alternator');
    indexListings();

    $this->get(route('search', ['q' => 'alternator', 'inspected' => 1, 'delivery' => 1]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.inspected', true)
            ->where('filters.delivery', true)
            ->missing('filters.verified'));
});

it('shows the fallback notice rather than an empty page', function (): void {
    published('Bosch dynamo unit');
    indexListings();

    $this->get(route('search', ['q' => 'hilux alternators 2012']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('notice', 'No exact matches — showing related parts')
            ->where('fallbackCategory.name', 'Alternators')
            ->where('listings.data.0.match.tier', 'related')
            ->has('listings.data', 1));
});

it('says nothing matched when nothing does', function (): void {
    indexListings();

    $this->get(route('search', ['q' => 'gearbox mounting bracket']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('listings.data', 0)
            ->where('notice', null));
});

it('remembers the buyer location it was given', function (): void {
    published('Toyota alternator');
    indexListings();

    $this->get(route('search', ['q' => 'alternator', 'lat' => -15.4167, 'lng' => 28.2833, 'radius_km' => 25]))
        ->assertInertia(fn ($page) => $page
            ->where('location.lat', -15.4167)
            ->where('location.radius_km', 25));
});

it('refuses half a location', function (): void {
    $this->get(route('search', ['q' => 'alternator', 'lat' => -15.4167]))
        ->assertSessionHasErrors('lng');
});

it('refuses a price range that runs backwards', function (): void {
    $this->get(route('search', ['min_price' => 50_000, 'max_price' => 10_000]))
        ->assertSessionHasErrors('max_price');
});
