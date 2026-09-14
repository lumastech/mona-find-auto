<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

it('lets a guest browse published listings in the envelope', function (): void {
    Product::factory()->count(2)->create();

    $this->getJson(route('api.v1.products.index'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'condition', 'inspection', 'price_ngwee']],
            'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
        ]);
});

it('carries both badges on every card', function (): void {
    Product::factory()->condition(Condition::CarBreaker)->create();

    $this->getJson(route('api.v1.products.index'))
        ->assertOk()
        ->assertJsonPath('data.0.condition.value', 'car_breaker')
        ->assertJsonPath('data.0.condition.label', 'Car Breaker')
        ->assertJsonPath('data.0.inspection.value', 'uninspected')
        ->assertJsonPath('data.0.inspection.inspected', false);
});

it('hides everything that is not published', function (): void {
    Product::factory()->create();
    Product::factory()->draft()->create();
    Product::factory()->pendingReview()->create();
    Product::factory()->create([
        'seller_id' => Seller::factory()->create([
            'verification_status' => VerificationStatus::Suspended,
        ])->id,
    ]);

    $this->getJson(route('api.v1.products.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('404s an unpublished listing in the platform error shape', function (): void {
    $draft = Product::factory()->draft()->create();

    $this->getJson(route('api.v1.products.show', $draft))
        ->assertNotFound()
        ->assertJsonStructure(['error' => ['code', 'message']]);
});

it('sends prices as integer ngwee', function (): void {
    $product = Product::factory()->create();
    $product->variants()->update(['price' => 125050]);

    $this->getJson(route('api.v1.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.price_ngwee', 125050)
        ->assertJsonPath('data.variants.0.price_ngwee', 125050);
});

it('masks seller contact details for an unauthenticated caller', function (): void {
    $seller = Seller::factory()->create(['email' => 'sales@kabwata.co.zm']);
    $product = Product::factory()->create(['seller_id' => $seller->id]);

    $response = $this->getJson(route('api.v1.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.seller.contact.visible', false);

    expect($response->getContent())->not->toContain('sales@kabwata.co.zm');
});

it('shows a token holder the real contact details', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $seller = Seller::factory()->create(['email' => 'sales@kabwata.co.zm']);
    $product = Product::factory()->create(['seller_id' => $seller->id]);

    $this->getJson(route('api.v1.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.seller.contact.visible', true)
        ->assertJsonPath('data.seller.contact.fields.1.value', 'sales@kabwata.co.zm');
});

it('filters by category, including everything beneath it', function (): void {
    $tree = app(CategoryTree::class);
    $engine = $tree->create(['name' => 'Engine']);
    $injectors = $tree->create(['name' => 'Fuel injectors'], $tree->create(['name' => 'Fuel system'], $engine));

    $wanted = Product::factory()->inCategory($injectors)->create();
    Product::factory()->inCategory(Category::factory()->create())->create();

    $this->getJson(route('api.v1.products.index', ['category_id' => $engine->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $wanted->id);
});

it('filters by make, condition and inspection badge', function (): void {
    $make = Make::factory()->named('Toyota')->create();

    $wanted = Product::factory()
        ->fitting($make)
        ->condition(Condition::BrandNew)
        ->inspected()
        ->create();

    Product::factory()->fitting($make)->condition(Condition::Used)->create();
    Product::factory()->condition(Condition::BrandNew)->inspected()->create();

    $this->getJson(route('api.v1.products.index', [
        'make_id' => $make->id,
        'condition' => Condition::BrandNew->value,
        'inspected' => 1,
    ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $wanted->id);
});

it('searches by part number, which is what a mechanic types', function (): void {
    $wanted = Product::factory()->create(['part_number' => '23670-0L050']);
    Product::factory()->create(['part_number' => 'ME202620']);

    $this->getJson(route('api.v1.products.index', ['search' => '23670']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $wanted->id);
});
