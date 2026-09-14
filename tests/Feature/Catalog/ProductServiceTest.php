<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Exceptions\ConditionNotAllowed;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->products = app(ProductService::class);
    $this->category = Category::factory()->create();

    /*
     * Pinned to a shop that may choose its own condition. The seller factory
     * picks a type at random, and a car breaker would have its condition
     * forced — which is its own test below, not a hazard for every other one.
     */
    $this->seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();
});

/**
 * A valid listing payload. Override any field to exercise a branch.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listingAttributes(Category $category, array $overrides = []): array
{
    return [
        'name' => 'Toyota Hilux 2KD fuel injector',
        'description' => 'A tested Denso injector off a 2KD-FTV, flow-matched on the bench.',
        'category_id' => $category->id,
        'condition' => Condition::Used,
        'sourcing' => PartSourcing::Oem,
        'price' => Money::ofKwacha('1250.50'),
        'quantity' => 3,
        ...$overrides,
    ];
}

it('creates a listing as an uninspected draft whatever the payload says', function (): void {
    $seller = $this->seller;

    $product = $this->products->create($seller, listingAttributes($this->category, [
        'status' => ListingStatus::Published,
        'inspection_status' => InspectionStatus::Inspected,
    ]));

    expect($product->status)->toBe(ListingStatus::Draft)
        ->and($product->inspection_status)->toBe(InspectionStatus::Uninspected);
});

it('stores a price as integer ngwee and never as a float', function (): void {
    $seller = $this->seller;

    $product = $this->products->create($seller, listingAttributes($this->category));
    $variant = $product->variants->first();

    expect($variant->price)->toBeMoney(125050)
        ->and(DB::table('product_variants')->where('id', $variant->id)->value('price'))
        ->toBe(125050);
});

it('gives a single-price listing one default variant', function (): void {
    $seller = $this->seller;

    $product = $this->products->create($seller, listingAttributes($this->category));

    expect($product->variants)->toHaveCount(1)
        ->and($product->variants->first()->is_default)->toBeTrue()
        ->and($product->variants->first()->name)->toBeNull()
        ->and($product->variants->first()->sku)->not->toBeEmpty();
});

it('forces the car breaker condition for a car breaker seller', function (): void {
    $seller = Seller::factory()->ofType(SellerType::CarBreaker)->create();

    $product = $this->products->create($seller, listingAttributes($this->category, ['condition' => null]));

    expect($product->condition)->toBe(Condition::CarBreaker);
});

it('refuses a car breaker listing that claims another condition', function (): void {
    $seller = Seller::factory()->ofType(SellerType::CarBreaker)->create();

    $this->products->create($seller, listingAttributes($this->category, ['condition' => Condition::BrandNew]));
})->throws(ConditionNotAllowed::class);

it('lets a shop that is not a breaker choose brand new', function (): void {
    $seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();

    $product = $this->products->create($seller, listingAttributes($this->category, [
        'condition' => Condition::BrandNew,
    ]));

    expect($product->condition)->toBe(Condition::BrandNew);
});

it('re-derives the condition on update so a breaker cannot edit its way out', function (): void {
    $seller = Seller::factory()->ofType(SellerType::CarBreaker)->create();
    $product = $this->products->create($seller, listingAttributes($this->category, ['condition' => null]));

    $this->products->update($product, ['condition' => Condition::Used]);
})->throws(ConditionNotAllowed::class);

it('keeps exactly one default when several variants are given', function (): void {
    $seller = $this->seller;

    $product = $this->products->create($seller, listingAttributes($this->category), [
        ['name' => 'Left hand', 'price' => Money::ofKwacha('900'), 'quantity' => 2],
        ['name' => 'Right hand', 'price' => Money::ofKwacha('950'), 'quantity' => 1, 'is_default' => true],
    ]);

    expect($product->variants)->toHaveCount(2)
        ->and($product->variants->where('is_default', true))->toHaveCount(1)
        ->and($product->variants->firstWhere('is_default', true)->name)->toBe('Right hand');
});

it('removes variants left out of an update', function (): void {
    $seller = $this->seller;
    $product = $this->products->create($seller, listingAttributes($this->category), [
        ['name' => 'Left hand', 'price' => Money::ofKwacha('900'), 'quantity' => 2],
        ['name' => 'Right hand', 'price' => Money::ofKwacha('950'), 'quantity' => 1],
    ]);

    $kept = $product->variants->firstWhere('name', 'Left hand');

    $this->products->update($product, [], [
        ['id' => $kept->id, 'name' => 'Left hand', 'price' => Money::ofKwacha('975'), 'quantity' => 4],
    ]);

    expect($product->variants)->toHaveCount(1)
        ->and($product->variants->first()->id)->toBe($kept->id)
        ->and($product->variants->first()->price)->toBeMoney(97500);
});

it('reports the cheapest variant as the from price', function (): void {
    $seller = $this->seller;
    $product = $this->products->create($seller, listingAttributes($this->category), [
        ['name' => 'Reconditioned', 'price' => Money::ofKwacha('2400'), 'quantity' => 1],
        ['name' => 'Used', 'price' => Money::ofKwacha('1200'), 'quantity' => 3],
    ]);

    expect($product->fromPrice())->toBeMoney(120000);
});

it('ignores status and inspection fields smuggled into an update', function (): void {
    $seller = $this->seller;
    $product = $this->products->create($seller, listingAttributes($this->category));

    $this->products->update($product, [
        'name' => 'Renamed listing that is long enough',
        'status' => ListingStatus::Published,
        'inspection_status' => InspectionStatus::Inspected,
        'seller_id' => Seller::factory()->create()->id,
    ]);

    expect($product->refresh()->name)->toBe('Renamed listing that is long enough')
        ->and($product->status)->toBe(ListingStatus::Draft)
        ->and($product->inspection_status)->toBe(InspectionStatus::Uninspected)
        ->and($product->seller_id)->toBe($seller->id);
});

it('gives every listing a unique slug', function (): void {
    $seller = $this->seller;

    $first = $this->products->create($seller, listingAttributes($this->category));
    $second = $this->products->create($seller, listingAttributes($this->category));

    expect($first->slug)->not->toBe($second->slug)
        ->and(Product::query()->count())->toBe(2);
});
