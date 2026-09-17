<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Enums\DisputeReason;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerPolicyService;
use App\Modules\Shopping\Services\CartService;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;

/*
 * The three areas over HTTP.
 *
 * These are about the seams the services cannot cover on their own: whose
 * order a page will show, which buttons a controller will accept, and whether
 * a stranger with an order number gets anything at all.
 */

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->machine = app(OrderStateMachine::class);

    $this->buyer = User::factory()->create();
    $this->buyer->assignRole(Role::Buyer->value);

    $this->sellerUser = User::factory()->create();
    $this->sellerUser->assignRole(Role::Seller->value);
    $this->seller = Seller::factory()->create(['user_id' => $this->sellerUser->getKey()]);

    /*
     * Two-factor is mandatory for staff, and EnsureStaffTwoFactor parks an
     * unenrolled account at /settings/security everywhere — so a moderator
     * fixture that skips it never reaches the console at all.
     */
    SpatieRole::findOrCreate(Role::Moderator->value, 'web');
    $this->moderator = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create();

    $product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $product->variants()->first();
    $this->variant->forceFill(['price' => 80_000, 'quantity' => 10])->save();
});

function webOrder(OrderStatus $status = OrderStatus::Paid, FulfilmentMethod $method = FulfilmentMethod::Pickup): Order
{
    $order = Order::factory()
        ->forBuyer(test()->buyer)
        ->forSeller(test()->seller)
        ->state(['fulfilment_method' => $method])
        ->create();

    OrderItem::factory()->forVariant(test()->variant, 1)->create(['order_id' => $order->getKey()]);

    $order = test()->machine->markPaid($order->refresh());

    while ($order->status !== $status && $order->status->canTransitionTo($status)) {
        break;
    }

    return $order;
}

it('shows a buyer their own orders and nobody else\'s', function (): void {
    $mine = webOrder();
    $theirs = Order::factory()->forSeller($this->seller)->create();

    $this->actingAs($this->buyer)
        ->get(route('orders.show', $mine))
        ->assertOk();

    $this->actingAs($this->buyer)
        ->get(route('orders.show', $theirs))
        ->assertForbidden();
});

it('sends a guest to log in rather than showing an order', function (): void {
    $this->get(route('orders.show', webOrder()))->assertRedirect(route('login'));
});

it('lets the buyer confirm receipt and refuses the seller the same button', function (): void {
    $order = webOrder();
    $sellerActor = OrderActor::forUser($this->sellerUser, $order);

    $this->machine->confirm($order, $sellerActor);
    $this->machine->markReady($order->refresh(), $sellerActor);
    $this->machine->markHandedOver($order->refresh(), $sellerActor);

    /* The seller cannot reach the buyer's button at all. */
    $this->actingAs($this->sellerUser)
        ->post(route('orders.confirm-receipt', $order))
        ->assertForbidden();

    $this->actingAs($this->buyer)
        ->post(route('orders.confirm-receipt', $order))
        ->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('streams the buyer a receipt and refuses the seller one', function (): void {
    $order = webOrder();

    $response = $this->actingAs($this->buyer)->get(route('orders.receipt', $order));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->sellerUser)
        ->get(route('orders.receipt', $order))
        ->assertForbidden();
});

it('streams the seller a packing slip and refuses the buyer one', function (): void {
    $order = webOrder();

    $this->actingAs($this->sellerUser)
        ->get(route('seller.orders.packing-slip', $order))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->buyer)
        ->get(route('seller.orders.packing-slip', $order))
        ->assertForbidden();
});

it('walks a seller through confirming, readying and handing over', function (): void {
    $order = webOrder();

    $this->actingAs($this->sellerUser)->post(route('seller.orders.confirm', $order), ['note' => 'On the shelf.']);
    expect($order->fresh()->status)->toBe(OrderStatus::SellerConfirmed);

    $this->actingAs($this->sellerUser)->post(route('seller.orders.ready', $order));
    expect($order->fresh()->status)->toBe(OrderStatus::ReadyForPickup);

    $this->actingAs($this->sellerUser)->post(route('seller.orders.handed-over', $order));
    expect($order->fresh()->status)->toBe(OrderStatus::Collected);
});

it('refuses a seller acting on another shop\'s order', function (): void {
    $other = User::factory()->create();
    $other->assignRole(Role::Seller->value);
    Seller::factory()->create(['user_id' => $other->getKey()]);

    $this->actingAs($other)
        ->post(route('seller.orders.confirm', webOrder()))
        ->assertForbidden();
});

it('opens a dispute from the buyer\'s order page', function (): void {
    Storage::fake('public');

    $order = webOrder();

    $this->actingAs($this->buyer)
        ->post(route('orders.disputes.store', $order), [
            'reason' => DisputeReason::WrongPart->value,
            'details' => 'The part is for a different engine family altogether.',
        ])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Disputed)
        ->and(OrderDispute::query()->count())->toBe(1);
});

it('insists a dispute says something a moderator can act on', function (): void {
    $this->actingAs($this->buyer)
        ->post(route('orders.disputes.store', webOrder()), [
            'reason' => DisputeReason::Damaged->value,
            'details' => 'Broken.',
        ])
        ->assertSessionHasErrors('details');
});

it('lets a moderator resolve a dispute and refuses the seller the same route', function (): void {
    $order = webOrder();

    $this->actingAs($this->buyer)->post(route('orders.disputes.store', $order), [
        'reason' => DisputeReason::Damaged->value,
        'details' => 'The casing arrived cracked right through.',
    ]);

    $dispute = OrderDispute::query()->firstOrFail();

    $this->actingAs($this->sellerUser)
        ->post(route('admin.disputes.resolve', $dispute), [
            'resolution' => 'release',
            'note' => 'Nothing wrong with it at all.',
        ])
        ->assertForbidden();

    $this->actingAs($this->moderator)
        ->post(route('admin.disputes.resolve', $dispute), [
            'resolution' => 'full_refund',
            'note' => 'Photographs show a clean break through the housing.',
        ])
        ->assertRedirect(route('admin.disputes.index'));

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('shows staff what the buyer accepted, version by version', function (): void {
    $policies = app(SellerPolicyService::class);

    foreach (PolicyType::cases() as $type) {
        $policies->publish($this->seller, $type, 'The '.$type->value.' policy, version one.');
    }

    app(CartService::class)->add($this->buyer, $this->variant, 1);

    $view = app(CheckoutService::class)->view($this->buyer);
    $group = $view->groups[0];

    $this->actingAs($this->buyer)->post(route('checkout.store'), [
        'payment_method' => PaymentMethod::Card->value,
        'selections' => [[
            'seller_id' => $this->seller->getKey(),
            'fulfilment_method' => FulfilmentMethod::Pickup->value,
            'accepted' => true,
            'accepted_policies' => array_map(
                static fn (array $policy): array => [
                    'policy_id' => $policy['policy_id'],
                    'version' => $policy['version'],
                ],
                $group->policyFingerprint(),
            ),
        ]],
    ])->assertRedirect();

    $order = Order::query()->latest('id')->firstOrFail();

    $this->actingAs($this->moderator)
        ->get(route('admin.orders.show', $order))
        ->assertOk();
});

it('refuses a checkout post that skipped the terms modal', function (): void {
    app(CartService::class)->add($this->buyer, $this->variant, 1);

    $this->actingAs($this->buyer)->post(route('checkout.store'), [
        'payment_method' => PaymentMethod::Card->value,
        'selections' => [[
            'seller_id' => $this->seller->getKey(),
            'fulfilment_method' => FulfilmentMethod::Pickup->value,
            'accepted' => false,
            'accepted_policies' => [],
        ]],
    ])->assertSessionHasErrors();

    expect(Order::query()->count())->toBe(0);
});

it('saves a shop\'s fulfilment settings and refuses a shop that offers nothing', function (): void {
    $this->actingAs($this->sellerUser)
        ->put(route('seller.fulfilment.update'), [
            'offers_pickup' => false,
            'offers_delivery' => false,
        ])
        ->assertSessionHasErrors('offers_pickup');

    $this->actingAs($this->sellerUser)
        ->put(route('seller.fulfilment.update'), [
            'offers_pickup' => true,
            'offers_delivery' => true,
            'delivery_fee' => '75.50',
            'delivery_note' => 'Lusaka only, next working day.',
        ])
        ->assertRedirect();

    expect($this->seller->fresh()->delivery_fee_ngwee->ngwee)->toBe(7_550);
});

/*
 * A nested JsonResource collection left unresolved reaches Inertia as an
 * object: Inertia calls toResponse() on any resource it finds in the props,
 * which applies the `data` wrapper, so `order.items` arrives as
 * `{ data: [...] }` and the page's v-for walks the wrapper instead of the
 * lines. Two items, because a wrapped collection still has a length of one.
 */
it('sends the order pages a plain list of items, not a wrapped collection', function (): void {
    $order = webOrder();
    OrderItem::factory()->forVariant($this->variant, 2)->create(['order_id' => $order->getKey()]);

    $this->actingAs($this->buyer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('order.items', 2));

    $this->actingAs($this->sellerUser)
        ->get(route('seller.orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('order.items', 2));
});
