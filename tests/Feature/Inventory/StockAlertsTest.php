<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Models\BackInStockSubscription;
use App\Modules\Inventory\Notifications\BackInStockNotification;
use App\Modules\Inventory\Notifications\LowStockAlert;
use App\Modules\Inventory\Services\BackInStockService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    $this->ledger = app(StockLedger::class);
    $this->subscriptions = app(BackInStockService::class);

    $this->seller = Seller::factory()->create();
    $this->product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill(['quantity' => 5, 'low_stock_threshold' => 2])->save();
});

it('warns the seller when a shelf falls to its threshold', function (): void {
    $this->ledger->setQuantity($this->variant, 2, StockMovementReason::SellerAdjustment);

    Notification::assertSentTo(
        $this->seller->user,
        LowStockAlert::class,
    );
});

it('warns the seller once, not once per sale', function (): void {
    $this->ledger->setQuantity($this->variant, 2, StockMovementReason::SellerAdjustment);
    $this->ledger->setQuantity($this->variant, 1, StockMovementReason::SellerAdjustment);

    Notification::assertSentToTimes($this->seller->user, LowStockAlert::class, 1);
});

it('warns again once the shelf has been refilled and falls back', function (): void {
    $this->ledger->setQuantity($this->variant, 2, StockMovementReason::SellerAdjustment);
    $this->ledger->setQuantity($this->variant, 10, StockMovementReason::SellerAdjustment);
    $this->ledger->setQuantity($this->variant, 2, StockMovementReason::SellerAdjustment);

    Notification::assertSentToTimes($this->seller->user, LowStockAlert::class, 2);
});

it('tells the seller when a shelf empties', function (): void {
    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);

    Notification::assertSentTo(
        $this->seller->user,
        LowStockAlert::class,
        fn (LowStockAlert $notification): bool => str_contains(
            $notification->toArray($this->seller->user)['level'],
            'out_of_stock',
        ),
    );
});

it('tells a waiting buyer when the shelf is refilled', function (): void {
    $buyer = User::factory()->create();

    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);
    $this->subscriptions->subscribe($this->variant, $buyer);

    $this->ledger->setQuantity($this->variant, 3, StockMovementReason::SellerAdjustment);

    Notification::assertSentTo($buyer, BackInStockNotification::class);
});

it('tells each subscriber exactly once, however often the shelf refills', function (): void {
    $buyer = User::factory()->create();

    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);
    $this->subscriptions->subscribe($this->variant, $buyer);

    /* Refilled, sold out, refilled again — one message. */
    $this->ledger->setQuantity($this->variant, 3, StockMovementReason::SellerAdjustment);
    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);
    $this->ledger->setQuantity($this->variant, 4, StockMovementReason::SellerAdjustment);

    Notification::assertSentToTimes($buyer, BackInStockNotification::class, 1);

    expect(BackInStockSubscription::query()->first()->notified_at)->not->toBeNull();
});

it('says nothing to a buyer when stock rises without having been empty', function (): void {
    $buyer = User::factory()->create();

    $this->subscriptions->subscribe($this->variant, $buyer);
    $this->ledger->setQuantity($this->variant, 20, StockMovementReason::SellerAdjustment);

    Notification::assertNotSentTo($buyer, BackInStockNotification::class);
});

it('revives a spent subscription when the buyer asks again', function (): void {
    $buyer = User::factory()->create();

    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);
    $this->subscriptions->subscribe($this->variant, $buyer);
    $this->ledger->setQuantity($this->variant, 2, StockMovementReason::SellerAdjustment);

    $this->ledger->setQuantity($this->variant, 0, StockMovementReason::SellerAdjustment);
    $this->subscriptions->subscribe($this->variant, $buyer);
    $this->ledger->setQuantity($this->variant, 2, StockMovementReason::SellerAdjustment);

    Notification::assertSentToTimes($buyer, BackInStockNotification::class, 2);

    /* Still one row: re-subscribing revives rather than accumulates. */
    expect(BackInStockSubscription::query()->count())->toBe(1);
});

it('lets a signed-in buyer subscribe from the listing page', function (): void {
    $buyer = User::factory()->create();
    $this->variant->forceFill(['quantity' => 0])->save();

    $this->actingAs($buyer)
        ->post(route('stock-alerts.store', $this->variant))
        ->assertRedirect();

    $this->assertDatabaseHas('back_in_stock_subscriptions', [
        'product_variant_id' => $this->variant->id,
        'user_id' => $buyer->id,
        'notified_at' => null,
    ]);
});

it('sends a guest to log in rather than subscribing them', function (): void {
    $this->post(route('stock-alerts.store', $this->variant))
        ->assertRedirect(route('login'));
});

it('will not subscribe a buyer to a listing they cannot see', function (): void {
    $hidden = Product::factory()->ofSeller($this->seller)->stockHidden()->create();
    $variant = $hidden->variants()->first();

    $this->actingAs(User::factory()->create())
        ->post(route('stock-alerts.store', $variant))
        ->assertNotFound();
});

it('lets a buyer stop waiting', function (): void {
    $buyer = User::factory()->create();
    $this->subscriptions->subscribe($this->variant, $buyer);

    $this->actingAs($buyer)
        ->delete(route('stock-alerts.destroy', $this->variant))
        ->assertRedirect();

    expect(BackInStockSubscription::query()->count())->toBe(0);
});
