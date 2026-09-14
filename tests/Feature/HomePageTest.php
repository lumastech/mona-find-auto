<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\StorefrontNavigation;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

it('serves the home page to a guest', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/Home')
            ->has('categories')
            ->has('inspected')
            ->has('freshlyConfirmed')
            ->has('mechanics')
            ->has('stats'));
});

it('counts a root category by its whole subtree, not its direct children', function (): void {
    $tree = app(CategoryTree::class);

    $root = $tree->create(['name' => 'Engine']);
    $group = $tree->create(['name' => 'Fuel system'], $root);
    $leaf = $tree->create(['name' => 'Injectors'], $group);

    Product::factory()->inCategory($leaf)->create();

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.slug', $root->slug)
            ->where('categories.0.listings', 1));
});

it('leaves an empty category out rather than offering a dead end', function (): void {
    $tree = app(CategoryTree::class);
    $tree->create(['name' => 'Bodywork']);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('categories', []));
});

it('shows only listings a buyer may actually see', function (): void {
    Product::factory()->create(['name' => 'Published coil']);
    Product::factory()->draft()->create(['name' => 'Draft coil']);
    Product::factory()->stockHidden()->create(['name' => 'Hidden coil']);

    $this->get(route('home'))
        ->assertInertia(function ($page): void {
            $names = collect($page->toArray()['props']['freshlyConfirmed'])->pluck('name');

            expect($names)->toContain('Published coil')
                ->not->toContain('Draft coil')
                ->not->toContain('Hidden coil');
        });
});

it('fills the inspected rail with inspected listings only', function (): void {
    Product::factory()->inspected()->create(['name' => 'Checked alternator']);
    Product::factory()->create(['name' => 'Unchecked alternator']);

    $this->get(route('home'))
        ->assertInertia(function ($page): void {
            $rail = collect($page->toArray()['props']['inspected']);

            expect($rail->pluck('name'))->toContain('Checked alternator')
                ->not->toContain('Unchecked alternator');
            expect($rail->every(fn (array $card): bool => $card['inspection']['inspected'] === true))->toBeTrue();
        });
});

it('orders the fresh rail by when the seller last confirmed the shelf', function (): void {
    Product::factory()->stockConfirmedDaysAgo(4)->create(['name' => 'Confirmed on Monday']);
    Product::factory()->stockConfirmedDaysAgo(1)->create(['name' => 'Confirmed yesterday']);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('freshlyConfirmed.0.name', 'Confirmed yesterday'));
});

it('counts only verified sellers in the trust figures', function (): void {
    Seller::factory()->create(['verification_status' => VerificationStatus::Verified]);
    Seller::factory()->create(['verification_status' => VerificationStatus::UnderReview]);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('stats.verified_sellers', 1));
});

it('shares the header navigation with every storefront page', function (): void {
    $make = Make::factory()->create(['name' => 'Toyota', 'is_active' => true]);
    VehicleModel::factory()->for($make, 'make')->create(['name' => 'Hilux', 'is_active' => true]);
    app(CategoryTree::class)->create(['name' => 'Brakes']);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->has('nav.categories')
            ->has('nav.years')
            ->where('nav.makes.0.name', 'Toyota')
            ->where('nav.makes.0.models.0.name', 'Hilux'));
});

it('withholds the navigation from the seller portal', function (): void {
    $seller = User::factory()->create();
    $seller->assignRole(Role::Seller->value);

    $this->actingAs($seller)
        ->get(route('seller.dashboard'))
        ->assertInertia(fn ($page) => $page->where('nav', null));
});

it('rebuilds the cached navigation when staff curate reference data', function (): void {
    $navigation = app(StorefrontNavigation::class);

    Make::factory()->create(['name' => 'Toyota', 'is_active' => true]);
    expect(collect($navigation->payload()['makes'])->pluck('name'))->toContain('Toyota');

    Make::factory()->create(['name' => 'Nissan', 'is_active' => true]);
    expect(collect($navigation->payload()['makes'])->pluck('name'))->toContain('Nissan');
});

it('drops a deactivated category out of the menu', function (): void {
    $category = app(CategoryTree::class)->create(['name' => 'Exhausts']);

    expect(collect(app(StorefrontNavigation::class)->payload()['categories'])->pluck('name'))
        ->toContain('Exhausts');

    Category::query()->whereKey($category->getKey())->first()?->update(['is_active' => false]);

    expect(collect(app(StorefrontNavigation::class)->payload()['categories'])->pluck('name'))
        ->not->toContain('Exhausts');
});
