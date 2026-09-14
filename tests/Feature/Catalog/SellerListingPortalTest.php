<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    $this->seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();
    $this->category = Category::factory()->create();
});

/**
 * The listing form payload, as the Vue form posts it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listingForm(Category $category, array $overrides = []): array
{
    return [
        'name' => 'Toyota Hilux 2KD fuel injector',
        'description' => 'A tested Denso injector off a 2KD-FTV, flow-matched on the bench.',
        'category_id' => $category->id,
        'condition' => Condition::Used->value,
        'sourcing' => PartSourcing::Oem->value,
        'price' => '1250.50',
        'quantity' => 3,
        'delivery_available' => true,
        ...$overrides,
    ];
}

it('keeps buyers out of the listing portal', function (): void {
    $buyer = User::factory()->withRole(Role::Buyer)->create();

    $this->actingAs($buyer)->get(route('seller.listings.index'))->assertForbidden();
});

it('shows a seller their own listings and nobody else\'s', function (): void {
    $mine = Product::factory()->create(['seller_id' => $this->seller->id]);
    $theirs = Product::factory()->create();

    $this->actingAs($this->seller->user)
        ->get(route('seller.listings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('seller/listings/Index')
            ->has('listings.data', 1)
            ->where('listings.data.0.id', $mine->id));

    expect($theirs->seller_id)->not->toBe($this->seller->id);
});

it('creates a listing as a draft and sends the seller to its form', function (): void {
    $this->actingAs($this->seller->user)
        ->post(route('seller.listings.store'), listingForm($this->category))
        ->assertRedirect();

    $product = Product::query()->firstOrFail();

    expect($product->status)->toBe(ListingStatus::Draft)
        ->and($product->seller_id)->toBe($this->seller->id)
        ->and($product->variants()->first()->price)->toBeMoney(125050);
});

it('rejects a car breaker posting a brand new condition', function (): void {
    $breaker = Seller::factory()->ofType(SellerType::CarBreaker)->create();

    $this->actingAs($breaker->user)
        ->post(route('seller.listings.store'), listingForm($this->category, [
            'condition' => Condition::BrandNew->value,
        ]))
        ->assertSessionHasErrors('condition');

    expect(Product::query()->count())->toBe(0);
});

it('badges a car breaker listing Car Breaker without being asked', function (): void {
    $breaker = Seller::factory()->ofType(SellerType::CarBreaker)->create();

    $this->actingAs($breaker->user)
        ->post(route('seller.listings.store'), listingForm($this->category, [
            'condition' => Condition::CarBreaker->value,
        ]));

    expect(Product::query()->firstOrFail()->condition)->toBe(Condition::CarBreaker);
});

it('offers a car breaker only the one condition it may use', function (): void {
    $breaker = Seller::factory()->ofType(SellerType::CarBreaker)->create();

    $this->actingAs($breaker->user)
        ->get(route('seller.listings.create'))
        ->assertInertia(fn ($page) => $page
            ->has('conditions', 1)
            ->where('conditions.0.value', Condition::CarBreaker->value)
            ->where('conditionLocked', Condition::CarBreaker->value));
});

it('offers a shop that is not a breaker both conditions', function (): void {
    $this->actingAs($this->seller->user)
        ->get(route('seller.listings.create'))
        ->assertInertia(fn ($page) => $page
            ->has('conditions', 2)
            ->where('conditionLocked', null));
});

it('refuses to let one seller edit another seller listing', function (): void {
    $theirs = Product::factory()->draft()->create();

    $this->actingAs($this->seller->user)
        ->put(route('seller.listings.update', $theirs), listingForm($this->category))
        ->assertForbidden();
});

it('freezes a listing while a moderator has it', function (): void {
    $queued = Product::factory()->pendingReview()->create(['seller_id' => $this->seller->id]);

    $this->actingAs($this->seller->user)
        ->put(route('seller.listings.update', $queued), listingForm($this->category))
        ->assertForbidden();
});

it('warns about a duplicate part number without blocking it', function (): void {
    Product::factory()->create(['seller_id' => $this->seller->id, 'part_number' => '23670-0L050']);
    $second = Product::factory()->draft()->create([
        'seller_id' => $this->seller->id,
        'part_number' => '23670-0L050',
    ]);

    $this->actingAs($this->seller->user)
        ->get(route('seller.listings.edit', $second))
        ->assertInertia(fn ($page) => $page
            ->where('duplicateWarning.part_number', '23670-0L050')
            ->where('duplicateWarning.count', 1));
});

it('tells a seller which field is missing when a submission is refused', function (): void {
    $product = Product::factory()->draft()->create(['seller_id' => $this->seller->id]);

    $this->actingAs($this->seller->user)
        ->post(route('seller.listings.submit', $product))
        ->assertSessionHasErrors('photos');

    expect($product->refresh()->status)->toBe(ListingStatus::Draft);
});

it('sends a complete draft for review', function (): void {
    $product = Product::factory()->draft()->create(['seller_id' => $this->seller->id]);
    $product->addMedia(UploadedFile::fake()->image('part.jpg'))->toMediaCollection(Product::PHOTOS_COLLECTION);

    $this->actingAs($this->seller->user)
        ->post(route('seller.listings.submit', $product->refresh()))
        ->assertRedirect();

    expect($product->refresh()->status)->toBe(ListingStatus::PendingReview);
});

it('lets a seller upload photos up to the limit', function (): void {
    $product = Product::factory()->draft()->create(['seller_id' => $this->seller->id]);

    $this->actingAs($this->seller->user)
        ->post(route('seller.listings.photos.store', $product), [
            'photos' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
        ])
        ->assertRedirect();

    expect($product->refresh()->getMedia(Product::PHOTOS_COLLECTION))->toHaveCount(2);
});

it('archives rather than deletes, so order history keeps its listing', function (): void {
    $product = Product::factory()->create(['seller_id' => $this->seller->id]);

    $this->actingAs($this->seller->user)
        ->delete(route('seller.listings.destroy', $product))
        ->assertRedirect(route('seller.listings.index'));

    expect($product->refresh()->status)->toBe(ListingStatus::Archived)
        ->and(Product::query()->whereKey($product->id)->exists())->toBeTrue();
});
