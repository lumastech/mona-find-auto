<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Models\Quotation;
use App\Modules\Shopping\Services\CartService;
use App\Modules\Shopping\Services\WishlistService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();

    $this->buyer = User::factory()->create();

    $this->seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();
    $this->product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill(['price' => kwacha(450), 'quantity' => 10])->save();
});

/*
|--------------------------------------------------------------------------
| /api/v1/wishlist
|--------------------------------------------------------------------------
*/

it('turns away a wishlist request with no token', function (): void {
    $this->getJson(route('api.v1.wishlist.index'))->assertUnauthorized();
});

it('saves and lists a listing in the envelope', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson(route('api.v1.wishlist.store', $this->product))
        ->assertCreated()
        ->assertJsonPath('data.listing.id', $this->product->id)
        ->assertJsonPath('data.change.price_dropped', false);

    $this->getJson(route('api.v1.wishlist.index'))
        ->assertOk()
        ->assertJsonPath('meta.count', 1)
        ->assertJsonStructure(['data' => [['id', 'saved_at', 'listing', 'change', 'purchasable']]]);
});

it('reports a price drop through the API exactly as the page does', function (): void {
    Sanctum::actingAs($this->buyer);
    app(WishlistService::class)->add($this->buyer, $this->product);

    $this->variant->forceFill(['price' => kwacha(380)])->save();

    $this->getJson(route('api.v1.wishlist.index'))
        ->assertOk()
        ->assertJsonPath('data.0.change.price_dropped', true)
        ->assertJsonPath('data.0.change.difference_ngwee', -kwacha(70)->ngwee);
});

it('moves a saved listing to the cart and answers with the cart', function (): void {
    Sanctum::actingAs($this->buyer);
    app(WishlistService::class)->add($this->buyer, $this->product);

    $this->postJson(route('api.v1.wishlist.move-to-cart', $this->product))
        ->assertOk()
        ->assertJsonPath('data.unit_count', 1)
        ->assertJsonPath('data.seller_count', 1);
});

it('refuses to move a sold-out listing, in the error envelope', function (): void {
    Sanctum::actingAs($this->buyer);
    app(WishlistService::class)->add($this->buyer, $this->product);
    $this->variant->forceFill(['quantity' => 0])->save();

    $this->postJson(route('api.v1.wishlist.move-to-cart', $this->product))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'listing_not_purchasable');
});

it('unsaves a listing', function (): void {
    Sanctum::actingAs($this->buyer);
    app(WishlistService::class)->add($this->buyer, $this->product);

    $this->deleteJson(route('api.v1.wishlist.destroy', $this->product))->assertNoContent();

    $this->getJson(route('api.v1.wishlist.index'))->assertJsonPath('meta.count', 0);
});

/*
|--------------------------------------------------------------------------
| /api/v1/cart
|--------------------------------------------------------------------------
*/

it('turns away a cart request with no token', function (): void {
    $this->getJson(route('api.v1.cart.index'))->assertUnauthorized();
});

it('adds to the cart and answers with the whole grouped cart', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson(route('api.v1.cart.store'), [
        'variant_id' => $this->variant->id,
        'quantity' => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.unit_count', 2)
        ->assertJsonPath('data.total_ngwee', kwacha(900)->ngwee)
        ->assertJsonPath('data.groups.0.seller.id', $this->seller->id)
        ->assertJsonStructure([
            'data' => [
                'groups' => [['seller', 'lines', 'subtotal_ngwee']],
                'total_ngwee', 'unit_count', 'seller_count', 'issues', 'removed',
            ],
        ]);
});

it('reports a changed price on the cart it answers with', function (): void {
    Sanctum::actingAs($this->buyer);
    app(CartService::class)->add($this->buyer, $this->variant, 1);

    $this->variant->forceFill(['price' => kwacha(500)])->save();

    $this->getJson(route('api.v1.cart.index'))
        ->assertOk()
        ->assertJsonPath('data.has_changes', true)
        ->assertJsonPath('data.blocks_checkout', false)
        ->assertJsonPath('data.groups.0.lines.0.unit_price_ngwee', kwacha(500)->ngwee)
        ->assertJsonPath('data.groups.0.lines.0.previous_unit_price_ngwee', kwacha(450)->ngwee)
        ->assertJsonPath('data.groups.0.lines.0.issues.0.value', 'price_changed');
});

it('will not let a token holder touch another buyer\'s line', function (): void {
    $line = app(CartService::class)->add($this->buyer, $this->variant, 1);

    Sanctum::actingAs(User::factory()->create());

    $this->patchJson(route('api.v1.cart.update', $line), ['quantity' => 4])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| /api/v1/quotations
|--------------------------------------------------------------------------
*/

it('turns away a quotations request with no token', function (): void {
    $this->getJson(route('api.v1.quotations.index'))->assertUnauthorized();
});

it('opens a request and lists it back to the buyer', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson(route('api.v1.quotations.store'), [
        'variant_id' => $this->variant->id,
        'quantity' => 25,
        'message' => 'Do you have twenty-five?',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status.value', 'open')
        ->assertJsonPath('data.quantity', 25);

    $this->getJson(route('api.v1.quotations.index'))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1);
});

it('lets a shop list and answer the requests addressed to it', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->create();

    Sanctum::actingAs($this->seller->user);

    $this->getJson(route('api.v1.quotations.index', ['role' => 'seller']))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1);

    $this->postJson(route('api.v1.quotations.respond', $quotation), [
        'unit_price' => '399.99',
        'valid_until' => now()->addDays(4)->toDateString(),
        'delivery_note' => 'Two working days.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status.value', 'quoted')
        ->assertJsonPath('data.quoted_unit_price_ngwee', 39999)
        ->assertJsonPath('data.is_acceptable', true);
});

it('refuses a quote from a shop the request was not addressed to', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->create();

    Sanctum::actingAs(Seller::factory()->ofType(SellerType::Garage)->create()->user);

    $this->postJson(route('api.v1.quotations.respond', $quotation), [
        'unit_price' => '1',
        'valid_until' => now()->addDay()->toDateString(),
    ])->assertForbidden();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Open);
});

it('tells an account with no shop that it has no seller side', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->getJson(route('api.v1.quotations.index', ['role' => 'seller']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'not_a_seller');
});

it('accepts a quote and answers with the cart it landed in', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)
        ->quantity(4)->quoted(kwacha(400))->create();

    Sanctum::actingAs($this->buyer);

    $this->postJson(route('api.v1.quotations.accept', $quotation))
        ->assertOk()
        ->assertJsonPath('data.unit_count', 4)
        ->assertJsonPath('data.groups.0.lines.0.unit_price_ngwee', kwacha(400)->ngwee)
        ->assertJsonPath('data.total_ngwee', kwacha(1600)->ngwee);
});

it('refuses to accept a stale quote', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->stale()->create();

    Sanctum::actingAs($this->buyer);

    $this->postJson(route('api.v1.quotations.accept', $quotation))->assertForbidden();
});

it('keeps one buyer out of another buyer\'s quotation', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->quoted()->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.quotations.show', $quotation))->assertForbidden();
});
