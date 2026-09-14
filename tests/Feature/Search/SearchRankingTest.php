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
use App\Modules\Search\Services\ProductSearch;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;

/*
 * These run against a real Meilisearch. The ordering under test is
 * Meilisearch's — ranking rules, matching strategy, geo filters — so a stub
 * would only prove that a stub does what the stub was told.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    usingMeilisearch();

    $this->search = app(ProductSearch::class);
    $this->category = Category::factory()->create(['name' => 'Brake pads']);
});

/**
 * A published listing with a known name and price, from a shop whose standing
 * the test decides.
 */
function listing(string $name, int $priceKwacha, array $product = [], array $seller = []): Product
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

function searchFor(string $term, array $overrides = []): array
{
    return resultNames(test()->search->search(SearchCriteria::fromArray(['q' => $term, ...$overrides])));
}

/**
 * Tier 1 above tier 2, in one list. This is the ranking the brief describes
 * and the reason the module runs one query rather than two: the listing
 * holding every word the buyer typed comes first, the near-miss follows it,
 * and the listing that shares only a part name does not appear at all.
 */
it('puts listings matching every word above listings matching only some', function (): void {
    listing('Toyota Hilux front brake pads', 300);
    listing('Toyota Corolla brake pads', 300);
    listing('Nissan brake pads', 300);

    indexListings();

    $results = $this->search->search(SearchCriteria::fromArray(['q' => 'toyota hilux brake pads']));
    $items = $results->listings->items();

    expect(resultNames($results))->toBe(['Toyota Hilux front brake pads', 'Toyota Corolla brake pads'])
        ->and($results->tierFor($items[0]))->toBe(MatchTier::Exact)
        ->and($results->tierFor($items[1]))->toBe(MatchTier::Partial)
        ->and($results->tier)->toBe(MatchTier::Exact)
        ->and($results->notice())->toBeNull();
});

/**
 * Tier 2, and how it is reached: with nothing matching all four words,
 * Meilisearch drops the last of them and the `words` ranking rule floats
 * whatever matched more of them back to the top.
 */
it('falls through to partial matches, best-matching first, when nothing matches every word', function (): void {
    listing('Toyota Hilux brake disc', 300);
    listing('Toyota Corolla wiper blade', 300);

    indexListings();

    $results = $this->search->search(SearchCriteria::fromArray(['q' => 'toyota hilux brake pads']));
    $items = $results->listings->items();

    expect(resultNames($results))->toBe(['Toyota Hilux brake disc', 'Toyota Corolla wiper blade'])
        ->and($results->tier)->toBe(MatchTier::Partial)
        ->and($results->tierFor($items[0]))->toBe(MatchTier::Partial)
        ->and($results->notice())->toBeNull();
});

it('calls a result exact when it holds every word the buyer typed', function (): void {
    listing('Toyota Hilux front brake pads', 300);

    indexListings();

    $results = $this->search->search(SearchCriteria::fromArray(['q' => 'hilux brake pads']));

    expect($results->tierFor($results->listings->items()[0]))->toBe(MatchTier::Exact);
});

/**
 * The heart of the ranking: three listings that match a query equally well,
 * separated only by the shops behind them and then by price.
 */
it('orders equal matches by quality score, then by price descending', function (): void {
    $weak = listing('Brake pads set', 400, [], ['verification_status' => VerificationStatus::UnderReview]);
    $strongCheap = listing('Brake pads kit', 200, ['inspection_status' => InspectionStatus::Inspected]);
    $strongDear = listing('Brake pads pack', 900, ['inspection_status' => InspectionStatus::Inspected]);

    indexListings();

    expect(searchFor('brake pads'))->toBe([
        /* Same score — the dearer of the two goes first. */
        $strongDear->name,
        $strongCheap->name,
        $weak->name,
    ]);
});

it('demotes an unverified seller below a verified one', function (): void {
    listing('Brake pads alpha', 500, [], ['verification_status' => VerificationStatus::UnderReview]);
    listing('Brake pads beta', 500);

    indexListings();

    expect(searchFor('brake pads'))->toBe(['Brake pads beta', 'Brake pads alpha']);
});

it('demotes a listing whose stock nobody has confirmed', function (): void {
    listing('Brake pads alpha', 500, ['freshness_state' => FreshnessState::Unconfirmed]);
    listing('Brake pads beta', 500);

    indexListings();

    expect(searchFor('brake pads'))->toBe(['Brake pads beta', 'Brake pads alpha']);
});

it('reorders the catalogue when an administrator changes a weight', function (): void {
    /* Inspected but unverified against verified but uninspected. */
    listing('Brake pads inspected', 500, ['inspection_status' => InspectionStatus::Inspected], [
        'verification_status' => VerificationStatus::UnderReview,
    ]);
    listing('Brake pads verified', 500);

    indexListings();

    expect(searchFor('brake pads')[0])->toBe('Brake pads verified');

    settings()->set('ranking.weight.inspected', 60);
    indexListings();

    expect(searchFor('brake pads')[0])->toBe('Brake pads inspected');
});

it('never shows a listing a buyer could not open', function (): void {
    listing('Brake pads published', 500);
    listing('Brake pads unpublished', 500, ['status' => ListingStatus::Draft, 'published_at' => null]);
    listing('Brake pads hidden', 500, ['freshness_state' => FreshnessState::Hidden]);
    listing('Brake pads suspended shop', 500, [], ['verification_status' => VerificationStatus::Suspended]);

    indexListings();

    expect(searchFor('brake pads'))->toBe(['Brake pads published']);
});

it('falls back to the closest category and says so when nothing matches', function (): void {
    listing('Ceramic disc set', 500);

    indexListings();

    $results = $this->search->search(SearchCriteria::fromArray(['q' => 'hilux brake pads 2012']));

    expect($results->tier)->toBe(MatchTier::Related)
        ->and($results->notice())->toBe('No exact matches — showing related parts')
        ->and($results->fallbackCategory?->name)->toBe('Brake pads')
        ->and(resultNames($results))->toBe(['Ceramic disc set']);
});

it('sorts by price in both directions when asked', function (): void {
    listing('Brake pads cheap', 100);
    listing('Brake pads dear', 900);

    indexListings();

    expect(searchFor('brake pads', ['sort' => SearchSort::PriceAsc->value]))
        ->toBe(['Brake pads cheap', 'Brake pads dear'])
        ->and(searchFor('brake pads', ['sort' => SearchSort::PriceDesc->value]))
        ->toBe(['Brake pads dear', 'Brake pads cheap']);
});
