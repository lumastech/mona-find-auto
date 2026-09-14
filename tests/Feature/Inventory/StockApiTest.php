<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Models\BackInStockSubscription;
use App\Modules\Sellers\Models\Seller;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seller = Seller::factory()->create();
    $this->seller->user->refresh();

    $this->product = Product::factory()->ofSeller($this->seller)->stockConfirmedDaysAgo(8)->create();
    $this->variant = $this->product->variants()->first();
});

it('answers a seller stock request in the envelope', function (): void {
    Sanctum::actingAs($this->seller->user);

    $this->getJson(route('api.v1.seller.stock.index'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'freshness' => ['value', 'days_since_confirmed'], 'variants']],
            'meta' => ['outstanding'],
        ])
        ->assertJsonPath('data.0.freshness.value', FreshnessState::Unconfirmed->value);
});

it('confirms a whole shop from the mobile app', function (): void {
    Sanctum::actingAs($this->seller->user);

    $this->postJson(route('api.v1.seller.stock.confirm'))
        ->assertOk()
        ->assertJsonPath('data.confirmed_listings', 1);

    expect($this->product->refresh()->freshness_state)->toBe(FreshnessState::Fresh);
});

it('confirms one listing from the mobile app', function (): void {
    Sanctum::actingAs($this->seller->user);

    $this->postJson(route('api.v1.seller.stock.confirm.product', $this->product->id))
        ->assertOk()
        ->assertJsonPath('data.freshness_state', FreshnessState::Fresh->value);
});

it('turns a guest away from the stock endpoints', function (): void {
    $this->getJson(route('api.v1.seller.stock.index'))->assertUnauthorized();
});

it('subscribes a buyer to a restock and unsubscribes them again', function (): void {
    $buyer = User::factory()->create();
    Sanctum::actingAs($buyer);

    $this->postJson(route('api.v1.stock-alerts.store', $this->variant->id))
        ->assertCreated()
        ->assertJsonPath('data.subscribed', true);

    expect(BackInStockSubscription::query()->count())->toBe(1);

    $this->deleteJson(route('api.v1.stock-alerts.destroy', $this->variant->id))
        ->assertOk()
        ->assertJsonPath('data.subscribed', false);

    expect(BackInStockSubscription::query()->count())->toBe(0);
});

it('gives the storefront API both the stock level and the freshness label', function (): void {
    $fresh = Product::factory()->create();

    $this->getJson(route('api.v1.products.show', $fresh->id))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'stock' => ['value', 'label', 'available'],
                'freshness' => ['value', 'label', 'confirmed_days_ago'],
                'variants' => [['level' => ['value', 'label']]],
                'stock_alerts',
            ],
        ]);
});

it('hides a listing nobody has vouched for from the storefront API', function (): void {
    $hidden = Product::factory()->stockHidden()->create();

    $this->getJson(route('api.v1.products.show', $hidden->id))->assertNotFound();
});
