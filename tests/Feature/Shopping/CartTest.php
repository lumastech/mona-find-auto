<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ListingModerationService;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Enums\CartLineIssue;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Services\CartService;

beforeEach(function (): void {
    $this->cart = app(CartService::class);
    $this->ledger = app(StockLedger::class);

    $this->buyer = User::factory()->create();

    $this->seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();
    $this->product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill(['price' => kwacha(450), 'quantity' => 10])->save();
});

it('keeps one cart per buyer and reuses it', function (): void {
    $first = $this->cart->for($this->buyer);
    $second = $this->cart->for($this->buyer->fresh());

    expect($second->getKey())->toBe($first->getKey());
});

it('adds to the quantity rather than making a second line', function (): void {
    $this->cart->add($this->buyer, $this->variant, 2);
    $line = $this->cart->add($this->buyer, $this->variant, 3);

    expect($line->quantity)->toBe(5)
        ->and(CartItem::query()->count())->toBe(1);
});

it('refuses a listing nobody may see', function (): void {
    app(ListingModerationService::class)->unpublish($this->product, null, 'Taken down for review.');

    expect(fn () => $this->cart->add($this->buyer, $this->variant->fresh()))
        ->toThrow(ListingNotPurchasable::class);
});

it('refuses an empty shelf', function (): void {
    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);

    expect(fn () => $this->cart->add($this->buyer, $this->variant->fresh()))
        ->toThrow(ListingNotPurchasable::class);
});

it('clamps an add to what the seller actually has', function (): void {
    $this->ledger->setQuantity($this->variant, 3, StockMovementReason::SellerAdjustment);

    $line = $this->cart->add($this->buyer, $this->variant->fresh(), 8);

    expect($line->quantity)->toBe(3);
});

it('groups the cart by shop, with a subtotal each', function (): void {
    $otherSeller = Seller::factory()->ofType(SellerType::CarBreaker)->create();
    $otherProduct = Product::factory()->ofSeller($otherSeller)->create();
    $otherVariant = $otherProduct->variants()->first();
    $otherVariant->forceFill(['price' => kwacha(1200), 'quantity' => 4])->save();

    $this->cart->add($this->buyer, $this->variant, 2);
    $this->cart->add($this->buyer, $otherVariant, 1);

    $view = $this->cart->view($this->buyer);

    expect($view->sellerCount())->toBe(2)
        ->and($view->groups[0]->subtotal())->toBeMoney(kwacha(900)->ngwee)
        ->and($view->groups[1]->subtotal())->toBeMoney(kwacha(1200)->ngwee)
        ->and($view->total())->toBeMoney(kwacha(2100)->ngwee)
        ->and($view->unitCount())->toBe(3);
});

it('reports a price change and shows the new price', function (): void {
    $this->cart->add($this->buyer, $this->variant, 2);

    $this->variant->forceFill(['price' => kwacha(520)])->save();

    $line = $this->cart->view($this->buyer)->lines()[0];

    expect($line->has(CartLineIssue::PriceChanged))->toBeTrue()
        ->and($line->unitPrice)->toBeMoney(kwacha(520)->ngwee)
        ->and($line->previousUnitPrice)->toBeMoney(kwacha(450)->ngwee)
        ->and($line->total())->toBeMoney(kwacha(1040)->ngwee);
});

it('reports a price change once, not on every visit', function (): void {
    $this->cart->add($this->buyer, $this->variant, 1);
    $this->variant->forceFill(['price' => kwacha(520)])->save();

    expect($this->cart->view($this->buyer)->lines()[0]->has(CartLineIssue::PriceChanged))->toBeTrue();

    /* The buyer has now been shown it; a notice that never clears is noise. */
    expect($this->cart->view($this->buyer)->lines()[0]->has(CartLineIssue::PriceChanged))->toBeFalse();
});

it('does not block checkout over a price that merely moved', function (): void {
    $this->cart->add($this->buyer, $this->variant, 1);
    $this->variant->forceFill(['price' => kwacha(520)])->save();

    $view = $this->cart->view($this->buyer);

    expect($view->hasChanges())->toBeTrue()
        ->and($view->blocksCheckout())->toBeFalse();
});

it('reduces a line the seller can no longer fill, and says so', function (): void {
    $this->cart->add($this->buyer, $this->variant, 6);

    $this->ledger->setQuantity($this->variant, 2, StockMovementReason::SellerAdjustment);

    $line = $this->cart->view($this->buyer)->lines()[0];

    expect($line->has(CartLineIssue::QuantityReduced))->toBeTrue()
        ->and($line->quantity)->toBe(2)
        ->and($line->requestedQuantity)->toBe(6)
        ->and($line->blocksCheckout())->toBeFalse();
});

it('flags an emptied shelf and holds the buyer at checkout', function (): void {
    $this->cart->add($this->buyer, $this->variant, 2);

    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);

    $view = $this->cart->view($this->buyer);

    expect($view->lines()[0]->has(CartLineIssue::OutOfStock))->toBeTrue()
        ->and($view->blocksCheckout())->toBeTrue()
        /* The line stays: the buyer decides whether to wait or drop it. */
        ->and($view->lineCount())->toBe(1);
});

it('removes a line whose listing was taken down, and names what went', function (): void {
    $this->cart->add($this->buyer, $this->variant, 1);

    app(ListingModerationService::class)->unpublish($this->product, null, 'Taken down for review.');

    $view = $this->cart->view($this->buyer);

    expect($view->isEmpty())->toBeTrue()
        ->and($view->removed)->toHaveCount(1)
        ->and($view->removed[0]->productName)->toBe($this->product->name)
        ->and($view->removed[0]->reason)->toBe(CartLineIssue::Unavailable)
        ->and(CartItem::query()->count())->toBe(0);
});

it('removes a line whose listing went unconfirmed long enough to be hidden', function (): void {
    $this->cart->add($this->buyer, $this->variant, 1);

    $this->product->fresh()->forceFill([
        'freshness_state' => FreshnessState::Hidden,
        'freshness_hidden_at' => now(),
    ])->save();

    expect($this->cart->view($this->buyer)->isEmpty())->toBeTrue();
});

it('removes a line whose shop was suspended', function (): void {
    $this->cart->add($this->buyer, $this->variant, 1);

    $this->seller->forceFill([
        'verification_status' => VerificationStatus::Suspended,
    ])->save();

    expect($this->cart->view($this->buyer)->isEmpty())->toBeTrue();
});

it('treats a quantity of zero as a removal', function (): void {
    $line = $this->cart->add($this->buyer, $this->variant, 2);

    $this->cart->updateQuantity($line, 0);

    expect(CartItem::query()->count())->toBe(0);
});

it('clamps an update to the shelf rather than refusing it', function (): void {
    $line = $this->cart->add($this->buyer, $this->variant, 1);
    $this->ledger->setQuantity($this->variant, 4, StockMovementReason::SellerAdjustment);

    $updated = $this->cart->updateQuantity($line->fresh(), 9);

    expect($updated->quantity)->toBe(4);
});

it('counts units rather than lines for the header badge', function (): void {
    $this->cart->add($this->buyer, $this->variant, 6);

    expect($this->cart->count($this->buyer))->toBe(6)
        ->and($this->cart->count(null))->toBe(0);
});

it('will not let one buyer touch another buyer\'s line', function (): void {
    $line = $this->cart->add($this->buyer, $this->variant, 1);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patch(route('cart.update', $line), ['quantity' => 5])
        ->assertNotFound();

    expect($line->fresh()->quantity)->toBe(1);
});

it('shows the buyer their grouped cart', function (): void {
    $this->cart->add($this->buyer, $this->variant, 2);

    $this->actingAs($this->buyer)
        ->get(route('cart.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/Cart')
            ->where('cart.seller_count', 1)
            ->where('cart.unit_count', 2)
            ->where('cart.total_ngwee', kwacha(900)->ngwee)
            ->has('cart.groups.0.lines', 1));
});

it('sends a guest adding to the cart to log in', function (): void {
    $this->post(route('cart.store'), ['variant_id' => $this->variant->id])
        ->assertRedirect(route('login'));
});
