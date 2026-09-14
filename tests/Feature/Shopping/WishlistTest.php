<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Models\WishlistItem;
use App\Modules\Shopping\Services\WishlistService;
use App\Support\Money\Money;

beforeEach(function (): void {
    $this->wishlist = app(WishlistService::class);
    $this->ledger = app(StockLedger::class);

    $this->buyer = User::factory()->create();
    /* Pinned: SellerFactory picks a random type, and a breaker forces Used. */
    $this->seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();
    $this->product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill(['price' => kwacha(500), 'quantity' => 5])->save();
    $this->product->load('variants');
});

it('records what was true when the listing was saved', function (): void {
    $item = $this->wishlist->add($this->buyer, $this->product);

    expect($item->price_ngwee_at_save)->toBeMoney(kwacha(500)->ngwee)
        ->and($item->in_stock_at_save)->toBeTrue();
});

it('does not move the baseline when the same listing is saved again', function (): void {
    $this->wishlist->add($this->buyer, $this->product);

    $this->variant->forceFill(['price' => kwacha(400)])->save();
    $this->product->load('variants');

    $item = $this->wishlist->add($this->buyer, $this->product);

    /* A second press is not a second save — the drop must survive it. */
    expect($item->price_ngwee_at_save)->toBeMoney(kwacha(500)->ngwee)
        ->and(WishlistItem::query()->count())->toBe(1);
});

it('reports a price drop against the saved price', function (): void {
    $item = $this->wishlist->add($this->buyer, $this->product);

    $this->variant->forceFill(['price' => kwacha(420)])->save();

    $change = $item->fresh()->load('product.variants')->change();

    expect($change->priceDropped())->toBeTrue()
        ->and($change->priceRose())->toBeFalse()
        ->and($change->priceDifferenceAmount())->toBeMoney(kwacha(80)->ngwee)
        ->and($change->isNoteworthy())->toBeTrue();
});

it('reports a price rise as well as a drop', function (): void {
    $item = $this->wishlist->add($this->buyer, $this->product);

    $this->variant->forceFill(['price' => kwacha(650)])->save();

    $change = $item->fresh()->load('product.variants')->change();

    expect($change->priceRose())->toBeTrue()
        ->and($change->priceDropped())->toBeFalse()
        ->and($change->priceDifferenceAmount())->toBeMoney(kwacha(150)->ngwee);
});

it('reports nothing noteworthy when the listing has not moved', function (): void {
    $item = $this->wishlist->add($this->buyer, $this->product);

    $change = $item->fresh()->load('product.variants')->change();

    expect($change->isNoteworthy())->toBeFalse()
        ->and($change->difference)->toBeMoney(0);
});

it('reports a listing that sold out since it was saved', function (): void {
    $item = $this->wishlist->add($this->buyer, $this->product);

    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);

    $change = $item->fresh()->load('product.variants')->change();

    expect($change->wentOutOfStock())->toBeTrue()
        ->and($change->isInStock)->toBeFalse()
        ->and($change->cameBackInStock())->toBeFalse();
});

it('reports a listing that came back into stock', function (): void {
    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);
    $this->product->load('variants');

    $item = $this->wishlist->add($this->buyer, $this->product);

    $this->ledger->setQuantity($this->variant, 4, StockMovementReason::SellerAdjustment);

    $change = $item->fresh()->load('product.variants')->change();

    expect($change->cameBackInStock())->toBeTrue()
        ->and($change->wentOutOfStock())->toBeFalse();
});

it('moves a saved listing into the cart and takes it off the list', function (): void {
    $this->wishlist->add($this->buyer, $this->product);

    $line = $this->wishlist->moveToCart($this->buyer, $this->product, 2);

    expect($line->quantity)->toBe(2)
        ->and($line->product_variant_id)->toBe($this->variant->getKey())
        ->and(WishlistItem::query()->forUser($this->buyer)->count())->toBe(0);
});

it('refuses to move a sold-out listing and leaves it saved', function (): void {
    $this->wishlist->add($this->buyer, $this->product);
    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);

    expect(fn () => $this->wishlist->moveToCart($this->buyer->fresh(), $this->product->fresh()))
        ->toThrow(ListingNotPurchasable::class);

    expect(WishlistItem::query()->forUser($this->buyer)->count())->toBe(1)
        ->and(CartItem::query()->count())->toBe(0);
});

it('picks the cheapest option with stock when the default has sold out', function (): void {
    $this->variant->forceFill(['is_default' => true, 'quantity' => 0])->save();

    $cheap = $this->product->variants()->create([
        'sku' => 'CHEAP-1',
        'name' => 'Aftermarket',
        'price' => kwacha(300),
        'quantity' => 3,
        'is_default' => false,
        'position' => 2,
    ]);

    $this->product->variants()->create([
        'sku' => 'DEAR-1',
        'name' => 'OEM',
        'price' => kwacha(900),
        'quantity' => 3,
        'is_default' => false,
        'position' => 3,
    ]);

    expect($this->wishlist->variantToBuy($this->product->fresh())->getKey())->toBe($cheap->getKey());
});

it('sends a guest pressing the heart to log in, and back to the listing', function (): void {
    $listingUrl = route('listings.show', $this->product);

    /*
     * The heart is a real POST to a guarded route, so the intended URL
     * Laravel keeps for a non-GET request is where the buyer came from —
     * which is the listing page, exactly where they should land after
     * logging in.
     */
    $this->from($listingUrl)
        ->post(route('wishlist.store', $this->product))
        ->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe($listingUrl);
});

it('shows a buyer their own wishlist and nobody else\'s', function (): void {
    $other = User::factory()->create();
    WishlistItem::factory()->forUser($other)->forProduct($this->product)->create();
    WishlistItem::factory()->forUser($this->buyer)->forProduct($this->product)->create();

    $this->actingAs($this->buyer)
        ->get(route('wishlist'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/Wishlist')
            ->has('items', 1)
            ->where('items.0.listing.id', $this->product->id));
});

it('counts nothing for a guest', function (): void {
    expect($this->wishlist->count(null))->toBe(0);
});

it('marks which of a page of listings this buyer has saved', function (): void {
    $other = Product::factory()->ofSeller($this->seller)->create();
    $this->wishlist->add($this->buyer, $this->product);

    $saved = $this->wishlist->savedIdsAmong($this->buyer, [$this->product->id, $other->id]);

    expect($saved)->toBe([$this->product->id]);
});

it('tells a guest nothing about what anybody saved', function (): void {
    $this->wishlist->add($this->buyer, $this->product);

    expect($this->wishlist->savedIdsAmong(null, [$this->product->id]))->toBe([]);
});

it('toggles the heart both ways', function (): void {
    expect($this->wishlist->toggle($this->buyer, $this->product))->toBeTrue()
        ->and($this->wishlist->toggle($this->buyer, $this->product))->toBeFalse()
        ->and(WishlistItem::query()->forUser($this->buyer)->count())->toBe(0);
});

it('measures a drop from a listing whose cheapest option changed', function (): void {
    $item = WishlistItem::factory()
        ->forUser($this->buyer)
        ->forProduct($this->product)
        ->savedAbove(kwacha(120))
        ->create();

    $change = $item->load('product.variants')->change();

    expect($change->priceDropped())->toBeTrue()
        ->and($change->priceDifferenceAmount())->toBeMoney(kwacha(120)->ngwee)
        ->and($change->savedPrice)->toBeMoney(Money::ofNgwee(kwacha(620)->ngwee)->ngwee);
});
