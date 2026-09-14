<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Models\SearchQuery;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    usingMeilisearch();

    $this->category = Category::factory()->create(['name' => 'Alternators']);
    $this->seller = Seller::factory()->create();
});

function apiListing(string $name, array $attributes = []): Product
{
    return Product::factory()->ofSeller(test()->seller)->create([
        'name' => $name,
        'category_id' => test()->category->getKey(),
        ...$attributes,
    ]);
}

it('answers in the platform envelope', function (): void {
    apiListing('Toyota alternator');
    indexListings();

    $this->getJson(route('api.v1.search', ['q' => 'alternator']))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'slug', 'name', 'condition', 'inspection', 'price_ngwee', 'match' => ['tier', 'label', 'explanation']]],
            'meta' => ['term', 'sort', 'tier', 'notice', 'filters', 'facets', 'pagination' => ['current_page', 'per_page', 'total', 'last_page']],
        ])
        ->assertJsonPath('data.0.name', 'Toyota alternator')
        ->assertJsonPath('meta.tier', 'exact');
});

it('takes the same parameters as the storefront page', function (): void {
    apiListing('Toyota alternator', ['inspection_status' => InspectionStatus::Inspected]);
    apiListing('Nissan alternator');
    indexListings();

    $this->getJson(route('api.v1.search', ['q' => 'alternator', 'inspected' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Toyota alternator')
        ->assertJsonPath('meta.filters.inspected', true);
});

it('reports the category fallback to the mobile app too', function (): void {
    apiListing('Bosch dynamo unit');
    indexListings();

    $this->getJson(route('api.v1.search', ['q' => 'hilux alternators 2012']))
        ->assertOk()
        ->assertJsonPath('meta.tier', 'related')
        ->assertJsonPath('meta.notice', 'No exact matches — showing related parts')
        ->assertJsonPath('data.0.match.tier', 'related');
});

it('is open to guests, like the storefront', function (): void {
    apiListing('Toyota alternator');
    indexListings();

    $this->getJson(route('api.v1.search', ['q' => 'alternator']))->assertOk();
});

it('logs an API search into the same report as a web one', function (): void {
    indexListings();

    $this->getJson(route('api.v1.search', ['q' => 'hilux bull bar']))->assertOk();

    expect(SearchQuery::query()->sole())
        ->term->toBe('hilux bull bar')
        ->zero_results->toBeTrue();
});

it('rejects a bad parameter in the error envelope', function (): void {
    $this->getJson(route('api.v1.search', ['sort' => 'cheapest']))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});
