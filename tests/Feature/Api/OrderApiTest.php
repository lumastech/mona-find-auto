<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Enums\DisputeReason;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerPolicyService;
use App\Modules\Shopping\Services\CartService;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * The buyer's orders through /api/v1.
 *
 * The point of these is that the API is a second front door and not a second
 * rulebook: it calls the same services, so what it refuses and what the web
 * area refuses have to be the same thing, in the same envelope.
 */

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->machine = app(OrderStateMachine::class);

    $this->buyer = User::factory()->create();
    $this->buyer->assignRole(Role::Buyer->value);

    $this->seller = Seller::factory()->create(['offers_delivery' => false]);

    $policies = app(SellerPolicyService::class);

    foreach (PolicyType::cases() as $type) {
        $policies->publish($this->seller, $type, 'The '.$type->value.' policy, version one.');
    }

    $product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $product->variants()->first();
    $this->variant->forceFill(['price' => 90_000, 'quantity' => 10])->save();
});

/**
 * The selections payload the app would post, accepting what is in force.
 *
 * @return array<int, array<string, mixed>>
 */
function apiSelections(): array
{
    $policies = test()->seller->currentPolicies()->get();

    return [[
        'seller_id' => test()->seller->getKey(),
        'fulfilment_method' => FulfilmentMethod::Pickup->value,
        'accepted' => true,
        'accepted_policies' => $policies
            ->map(static fn ($policy): array => [
                'policy_id' => $policy->getKey(),
                'version' => $policy->version,
            ])
            ->values()
            ->all(),
    ]];
}

it('refuses every order endpoint without a token', function (): void {
    $this->getJson('/api/v1/orders')->assertUnauthorized();
    $this->getJson('/api/v1/checkout')->assertUnauthorized();
});

it('describes the checkout, policies and all', function (): void {
    Sanctum::actingAs($this->buyer);
    app(CartService::class)->add($this->buyer, $this->variant, 2);

    $response = $this->getJson('/api/v1/checkout')->assertOk();

    expect($response->json('data.seller_count'))->toBe(1)
        ->and($response->json('data.groups.0.policies'))->toHaveCount(4)
        ->and($response->json('data.groups.0.policies.0.body'))->not->toBeEmpty()
        ->and($response->json('data.platform_terms.minimum_refund_statement'))->not->toBeEmpty()
        /* Collection only: this shop does not deliver. */
        ->and($response->json('data.groups.0.available_methods'))->toHaveCount(1);
});

it('places an order and returns the group the payment is addressed to', function (): void {
    Sanctum::actingAs($this->buyer);
    app(CartService::class)->add($this->buyer, $this->variant, 2);

    $response = $this->postJson('/api/v1/orders', [
        'payment_method' => PaymentMethod::MobileMoney->value,
        'selections' => apiSelections(),
    ])->assertCreated();

    expect($response->json('data.id'))->toHaveLength(26)
        ->and($response->json('data.status'))->toBe('pending_payment')
        ->and($response->json('data.orders'))->toHaveCount(1)
        ->and($response->json('data.total_ngwee'))->toBe(180_000);
});

it('refuses a placement that skipped the terms', function (): void {
    Sanctum::actingAs($this->buyer);
    app(CartService::class)->add($this->buyer, $this->variant, 1);

    $selections = apiSelections();
    $selections[0]['accepted'] = false;

    $this->postJson('/api/v1/orders', [
        'payment_method' => PaymentMethod::Card->value,
        'selections' => $selections,
    ])->assertStatus(422);

    expect(Order::query()->count())->toBe(0);
});

it('answers an empty cart in the error envelope rather than blowing up', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/v1/orders', [
        'payment_method' => PaymentMethod::Card->value,
        'selections' => apiSelections(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'checkout_unavailable');
});

it('lists and shows only this buyer\'s orders', function (): void {
    $mine = Order::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
    $theirs = Order::factory()->forSeller($this->seller)->create();

    Sanctum::actingAs($this->buyer);

    $response = $this->getJson('/api/v1/orders')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.number'))->toBe($mine->number)
        ->and($response->json('meta.pagination.total'))->toBe(1);

    $this->getJson('/api/v1/orders/'.$theirs->number)->assertForbidden();
});

it('confirms receipt through the API and refuses when the order is not there yet', function (): void {
    $order = Order::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
    OrderItem::factory()->forVariant($this->variant, 1)->create(['order_id' => $order->getKey()]);

    $order = $this->machine->markPaid($order->refresh());

    Sanctum::actingAs($this->buyer);

    /* Nothing has been handed over, so there is nothing to confirm. */
    $this->postJson('/api/v1/orders/'.$order->number.'/confirm-receipt')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_transition');

    $sellerActor = OrderActor::forUser($this->seller->user, $order);
    $this->machine->confirm($order, $sellerActor);
    $this->machine->markReady($order->refresh(), $sellerActor);
    $this->machine->markHandedOver($order->refresh(), $sellerActor);

    $this->postJson('/api/v1/orders/'.$order->number.'/confirm-receipt')
        ->assertOk()
        ->assertJsonPath('data.status', OrderStatus::Completed->value);
});

it('opens a dispute through the API', function (): void {
    $order = Order::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
    OrderItem::factory()->forVariant($this->variant, 1)->create(['order_id' => $order->getKey()]);

    $order = $this->machine->markPaid($order->refresh());

    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/v1/orders/'.$order->number.'/disputes', [
        'reason' => DisputeReason::NotReceived->value,
        'details' => 'The seller says it was collected but nobody from my garage went.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', OrderStatus::Disputed->value)
        ->assertJsonPath('data.dispute.reason', DisputeReason::NotReceived->value);
});
