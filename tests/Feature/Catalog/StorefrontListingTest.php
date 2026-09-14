<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

it('lets a guest read a published listing', function (): void {
    $product = Product::factory()->create();

    $this->get(route('listings.show', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/listings/Show')
            ->where('listing.id', $product->id)
            ->has('listing.condition')
            ->has('listing.inspection'));
});

it('always sends both badges to the listing page', function (): void {
    $product = Product::factory()->condition(Condition::CarBreaker)->create();

    $this->get(route('listings.show', $product))
        ->assertInertia(fn ($page) => $page
            ->where('listing.condition.value', Condition::CarBreaker->value)
            ->where('listing.condition.label', 'Car Breaker')
            ->where('listing.inspection.value', InspectionStatus::Uninspected->value)
            ->where('listing.inspection.label', 'Uninspected'));
});

it('404s a draft, a queued listing and a suspended shop alike', function (string $state): void {
    $product = match ($state) {
        'draft' => Product::factory()->draft()->create(),
        'queued' => Product::factory()->pendingReview()->create(),
        'suspended' => Product::factory()->create([
            'seller_id' => Seller::factory()->create([
                'verification_status' => VerificationStatus::Suspended,
            ])->id,
        ]),
    };

    $this->get(route('listings.show', $product))->assertNotFound();
})->with(['draft', 'queued', 'suspended']);

it('masks the seller contact for a guest and never sends the real value', function (): void {
    $seller = Seller::factory()->create([
        'phone' => '+260977123456',
        'email' => 'sales@kabwata.co.zm',
        'contact_person' => 'Chanda Mwale',
    ]);
    $product = Product::factory()->create(['seller_id' => $seller->id]);

    $response = $this->get(route('listings.show', $product));

    $response->assertInertia(fn ($page) => $page->where('listing.seller.contact.visible', false));

    /* The masking is on the server: there is nothing real in the payload to unblur. */
    expect($response->getContent())->not->toContain('977123456')
        ->and($response->getContent())->not->toContain('sales@kabwata.co.zm');
});

it('shows a logged-in buyer the real contact details', function (): void {
    $seller = Seller::factory()->create(['email' => 'sales@kabwata.co.zm']);
    $product = Product::factory()->create(['seller_id' => $seller->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('listings.show', $product))
        ->assertInertia(fn ($page) => $page
            ->where('listing.seller.contact.visible', true)
            ->where('listing.seller.contact.fields.1.value', 'sales@kabwata.co.zm'));
});

it('serves the gallery from conversions rather than the private original', function (): void {
    $product = Product::factory()->create();
    $product->addMedia(UploadedFile::fake()->image('part.jpg', 900, 700))
        ->toMediaCollection(Product::PHOTOS_COLLECTION);

    $this->get(route('listings.show', $product->refresh()))
        ->assertInertia(fn ($page) => $page
            ->has('listing.photos', 1)
            ->where('listing.photos.0.web', fn (string $url): bool => str_ends_with($url, '.webp')));
});

it('sends the price as integer ngwee, never a formatted string', function (): void {
    $product = Product::factory()->create();
    $product->variants()->update(['price' => 125050]);

    $this->get(route('listings.show', $product))
        ->assertInertia(fn ($page) => $page
            ->where('listing.price_ngwee', 125050)
            ->where('listing.variants.0.price_ngwee', 125050));
});

it('browses a heading and finds the listings filed beneath it', function (): void {
    $tree = app(CategoryTree::class);
    $engine = $tree->create(['name' => 'Engine']);
    $fuel = $tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $tree->create(['name' => 'Fuel injectors'], $fuel);

    $listing = Product::factory()->inCategory($injectors)->create();
    Product::factory()->inCategory(Category::factory()->create())->create();

    $this->get(route('categories.show', $engine))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/categories/Show')
            ->has('listings.data', 1)
            ->where('listings.data.0.id', $listing->id));
});

it('filters a category down to inspected listings', function (): void {
    $category = Category::factory()->create();
    $inspected = Product::factory()->inCategory($category)->inspected()->create();
    Product::factory()->inCategory($category)->create();

    $this->get(route('categories.show', [$category, 'inspected' => 1]))
        ->assertInertia(fn ($page) => $page
            ->has('listings.data', 1)
            ->where('listings.data.0.id', $inspected->id));
});

it('lists the category tree for a guest', function (): void {
    $tree = app(CategoryTree::class);
    $engine = $tree->create(['name' => 'Engine']);
    $tree->create(['name' => 'Fuel system'], $engine);

    $this->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/categories/Index')
            ->has('tree', 1)
            ->has('tree.0.children', 1));
});
