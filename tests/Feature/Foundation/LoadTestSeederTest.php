<?php

declare(strict_types=1);

use App\Modules\Catalog\Database\Seeders\PartCategorySeeder;
use App\Modules\Catalog\Database\Seeders\VehicleReferenceSeeder;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Database\Seeders\ZambianLocationSeeder;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\LoadTestSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * The load-test corpus is a fixture the committed performance figures depend
 * on, so it is worth knowing it still builds — and, more importantly, that it
 * still has the SHAPE the figures assume.
 *
 * A corpus of five thousand identical listings would measure the query
 * planner's luck rather than the platform, and the way that regression
 * arrives is somebody "simplifying" the spread in this seeder.
 *
 * Run at a twentieth of the real size: the shape is what is being asserted,
 * not the volume.
 */
beforeEach(function (): void {
    $this->seed([
        SettingsSeeder::class,
        ZambianLocationSeeder::class,
        VehicleReferenceSeeder::class,
        PartCategorySeeder::class,
    ]);

    (new LoadTestSeeder(sellerCount: 5, listingCount: 250))->run();
});

it('builds the shops and listings it says it does', function (): void {
    expect(Seller::query()->where('business_name', 'like', 'Load Test %')->count())->toBe(5)
        ->and(Product::query()->where('slug', 'like', 'load-test-%')->count())->toBe(250);
});

it('gives every listing a priced, default variant', function (): void {
    $products = Product::query()->where('slug', 'like', 'load-test-%')->pluck('id');

    $variants = ProductVariant::query()->whereIn('product_id', $products)->get();

    expect($variants)->toHaveCount(250)
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->price->ngwee > 0))->toBeTrue()
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->is_default))->toBeTrue();
});

it('spreads prices across three orders of magnitude', function (): void {
    /*
     * Read as raw ngwee off the query builder: `price` is cast to Money on
     * the model, and Money is a value object rather than an integer.
     */
    $prices = ProductVariant::query()
        ->whereHas('product', fn ($query) => $query->where('slug', 'like', 'load-test-%'))
        ->toBase()
        ->pluck('price')
        ->map(static fn ($price): int => (int) $price);

    /*
     * Without a spread, "price descending" within a ranking tier sorts
     * nothing and the ranking test measures a tie-break that never fires.
     */
    expect($prices->max())->toBeGreaterThan($prices->min() * 100);
});

it('leaves something for every storefront filter to exclude', function (): void {
    $loadTest = Product::query()->where('slug', 'like', 'load-test-%');

    expect((clone $loadTest)->where('status', ListingStatus::Unpublished)->count())
        ->toBeGreaterThan(0)
        ->and(Seller::query()
            ->where('business_name', 'like', 'Load Test %')
            ->where('verification_status', '!=', VerificationStatus::Verified)
            ->count())
        ->toBeGreaterThan(0)
        ->and(ProductVariant::query()
            ->whereHas('product', fn ($query) => $query->where('slug', 'like', 'load-test-%'))
            ->where('quantity', 0)
            ->count())
        ->toBeGreaterThan(0);
});

it('spreads listings across categories, makes and freshness states', function (): void {
    $loadTest = Product::query()->where('slug', 'like', 'load-test-%');

    expect((clone $loadTest)->distinct()->count('category_id'))->toBeGreaterThan(1)
        ->and((clone $loadTest)->distinct()->count('make_id'))->toBeGreaterThan(1)
        /*
         * Freshness carries a ranking demotion. A corpus stuck on one state
         * would tie on that weight for every row.
         */
        ->and((clone $loadTest)->distinct()->count('freshness_state'))
        ->toBe(count(FreshnessState::cases()));
});

it('can be run twice without doubling the corpus', function (): void {
    (new LoadTestSeeder(sellerCount: 5, listingCount: 250))->run();

    expect(Product::query()->where('slug', 'like', 'load-test-%')->count())->toBe(250)
        ->and(Seller::query()->where('business_name', 'like', 'Load Test %')->count())->toBe(5);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    (new LoadTestSeeder(sellerCount: 5, listingCount: 300))->run();

    /* Still the 250 from beforeEach; the production run added nothing. */
    expect(Product::query()->where('slug', 'like', 'load-test-%')->count())->toBe(250);
});
