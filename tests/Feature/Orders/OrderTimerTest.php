<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Orders\Enums\DisputeReason;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Jobs\AutoCancelUnconfirmedOrders;
use App\Modules\Orders\Jobs\AutoCompleteFulfilledOrders;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\DisputeService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * The two sweeps that decide the cases nobody came back for.
 *
 * Every assertion here travels in time rather than manipulating a deadline
 * column, because the deadlines are the thing under test: they are written at
 * payment time from the settings in force then, and the whole point is that
 * they do not move afterwards.
 */

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->machine = app(OrderStateMachine::class);
    $this->disputes = app(DisputeService::class);

    $this->buyer = User::factory()->create();
    $this->seller = Seller::factory()->create();

    $this->product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill(['price' => 45_000, 'quantity' => 10])->save();
});

/**
 * A paid order for two of the shelf's ten, with its terms frozen.
 */
function paidOrder(FulfilmentMethod $method = FulfilmentMethod::Pickup, int $quantity = 2): Order
{
    $order = Order::factory()
        ->forBuyer(test()->buyer)
        ->forSeller(test()->seller)
        ->state(['fulfilment_method' => $method])
        ->create();

    OrderItem::factory()->forVariant(test()->variant, $quantity)->create(['order_id' => $order->getKey()]);

    return test()->machine->markPaid($order->refresh());
}

it('cancels an order the seller never confirmed, once the window has passed', function (): void {
    $order = paidOrder();

    expect($order->confirm_due_at?->diffInHours($order->paid_at, absolute: true))->toBe(24.0);

    /* One hour short: nothing happens. */
    $this->travel(23)->hours();
    (new AutoCancelUnconfirmedOrders)->handle($this->machine);
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    $this->travel(2)->hours();
    (new AutoCancelUnconfirmedOrders)->handle($this->machine);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->cancellation_reason)->toContain('did not confirm');
});

it('leaves a confirmed order alone however long it sits', function (): void {
    $order = paidOrder();
    $this->machine->confirm($order, OrderActor::forUser($this->seller->user, $order));

    $this->travel(10)->days();
    (new AutoCancelUnconfirmedOrders)->handle($this->machine);

    expect($order->fresh()->status)->toBe(OrderStatus::SellerConfirmed)
        ->and($order->fresh()->confirm_due_at)->toBeNull();
});

it('puts the stock back when an unconfirmed order is auto-cancelled', function (): void {
    $order = paidOrder(quantity: 2);

    /* Paying took the two off the shelf. */
    expect($this->variant->fresh()->quantity)->toBe(8);

    $this->travel(25)->hours();
    (new AutoCancelUnconfirmedOrders)->handle($this->machine);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->variant->fresh()->quantity)->toBe(10);

    /* And the ledger says why, both ways. */
    expect(StockMovement::query()->where('order_id', $order->getKey())->pluck('reason')->all())
        ->toContain(StockMovementReason::OrderPaid, StockMovementReason::OrderCancelled);
});

it('completes a collected order once the pickup window closes', function (): void {
    $order = paidOrder(FulfilmentMethod::Pickup);
    $sellerActor = OrderActor::forUser($this->seller->user, $order);

    $this->machine->confirm($order, $sellerActor);
    $this->machine->markReady($order->refresh(), $sellerActor);
    $order = $this->machine->markHandedOver($order->refresh(), $sellerActor);

    /* Three days, from settings, snapshotted onto the order at payment. */
    expect($order->auto_complete_window_days)->toBe(3);

    $this->travel(2)->days();
    (new AutoCompleteFulfilledOrders)->handle($this->machine);
    expect($order->fresh()->status)->toBe(OrderStatus::Collected);

    $this->travel(2)->days();
    (new AutoCompleteFulfilledOrders)->handle($this->machine);
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('gives a delivered order the longer window', function (): void {
    $order = paidOrder(FulfilmentMethod::Delivery);
    $sellerActor = OrderActor::forUser($this->seller->user, $order);

    $this->machine->confirm($order, $sellerActor);
    $this->machine->markReady($order->refresh(), $sellerActor);
    $order = $this->machine->markHandedOver($order->refresh(), $sellerActor);

    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->auto_complete_window_days)->toBe(7);

    $this->travel(5)->days();
    (new AutoCompleteFulfilledOrders)->handle($this->machine);
    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);

    $this->travel(3)->days();
    (new AutoCompleteFulfilledOrders)->handle($this->machine);
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('does not auto-complete an order the buyer has disputed', function (): void {
    $order = paidOrder(FulfilmentMethod::Pickup);
    $sellerActor = OrderActor::forUser($this->seller->user, $order);

    $this->machine->confirm($order, $sellerActor);
    $this->machine->markReady($order->refresh(), $sellerActor);
    $order = $this->machine->markHandedOver($order->refresh(), $sellerActor);

    $this->disputes->open(
        $order->refresh(),
        $this->buyer,
        DisputeReason::WrongPart,
        'The alternator is for a 2JZ and my car is a 1NZ-FE.',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Disputed)
        ->and($order->fresh()->auto_complete_at)->toBeNull();

    $this->travel(30)->days();
    (new AutoCompleteFulfilledOrders)->handle($this->machine);

    expect($order->fresh()->status)->toBe(OrderStatus::Disputed);
});

it('runs against the window the order was frozen with, not today\'s setting', function (): void {
    $order = paidOrder(FulfilmentMethod::Pickup);
    $sellerActor = OrderActor::forUser($this->seller->user, $order);

    $this->machine->confirm($order, $sellerActor);
    $this->machine->markReady($order->refresh(), $sellerActor);
    $order = $this->machine->markHandedOver($order->refresh(), $sellerActor);

    $deadline = $order->auto_complete_at;

    /* An administrator lengthens the pickup window to a fortnight. */
    settings()->set('escrow.pickup_window_days', 14);

    expect($order->fresh()->auto_complete_at?->toDateTimeString())
        ->toBe($deadline?->toDateTimeString());

    $this->travel(4)->days();
    (new AutoCompleteFulfilledOrders)->handle($this->machine);

    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('is safe to run when there is nothing to do', function (): void {
    (new AutoCancelUnconfirmedOrders)->handle($this->machine);
    (new AutoCompleteFulfilledOrders)->handle($this->machine);
})->throwsNoExceptions();
