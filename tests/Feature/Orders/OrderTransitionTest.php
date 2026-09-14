<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderCompleted;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderStateChanged;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Exceptions\UnauthorisedOrderAction;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use App\Modules\Sellers\Models\Seller;
use App\Support\Database\ImmutableRecordException;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role as SpatieRole;

/*
 * The lifecycle, exhaustively.
 *
 * The pair of table-driven tests at the top are the important ones: they walk
 * EVERY status against EVERY other status and assert the machine agrees with
 * OrderStatus::allowedTransitions() in both directions. A hand-written list of
 * interesting cases would drift the moment somebody adds a state; this cannot.
 */

beforeEach(function (): void {
    /* Escrow windows and commission defaults come from settings. */
    $this->seed(SettingsSeeder::class);

    $this->machine = app(OrderStateMachine::class);

    $this->buyer = User::factory()->create();
    $this->sellerUser = User::factory()->create();
    $this->seller = Seller::factory()->create(['user_id' => $this->sellerUser->getKey()]);

    $this->order = Order::factory()
        ->forBuyer($this->buyer)
        ->forSeller($this->seller)
        ->create();
});

/**
 * Put an order into a given state without going through the machine, so a
 * transition test can start anywhere.
 */
function orderIn(OrderStatus $status): Order
{
    /** @var Order $order */
    $order = test()->order;

    /*
     * The machine writes through its own locked instance, so the one held by
     * the test is stale after every move. Without this re-read, forceFill
     * would find the status "already" correct, save nothing, and leave the
     * row wherever the last transition put it.
     */
    $order->refresh();

    $order->forceFill([
        'status' => $status,
        'paid_at' => $status->isPaid() && $status !== OrderStatus::PendingPayment ? now() : null,
        'auto_complete_window_days' => 3,
        'seller_confirm_window_hours' => 24,
    ])->save();

    return $order->refresh();
}

it('allows exactly the transitions the lifecycle declares', function (): void {
    foreach (OrderStatus::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            $order = orderIn($from);

            /*
             * Whoever the target state admits. Paid is System-only — a
             * payment is the gateway's word, never a person's — so this
             * cannot simply use staff everywhere.
             */
            $actor = in_array(OrderActorType::Staff, $to->actorsAllowedToEnter(), true)
                ? staffActor($order)
                : OrderActor::system();

            $moved = test()->machine->transition($order, $to, $actor);

            expect($moved->status)->toBe($to);
        }
    }
});

it('refuses every transition the lifecycle does not declare', function (): void {
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            if ($from->canTransitionTo($to)) {
                continue;
            }

            $order = orderIn($from);

            expect(fn () => test()->machine->transition($order, $to, staffActor($order)))
                ->toThrow(InvalidOrderTransition::class);
        }
    }
});

it('will not let a seller complete their own order', function (): void {
    $order = orderIn(OrderStatus::Collected);

    expect(fn () => test()->machine->complete($order, OrderActor::forUser($this->sellerUser, $order)))
        ->toThrow(UnauthorisedOrderAction::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Collected);
});

it('will not let a buyer confirm or dispatch an order', function (): void {
    $order = orderIn(OrderStatus::Paid);
    $buyerActor = OrderActor::forUser($this->buyer, $order);

    expect(fn () => test()->machine->confirm($order, $buyerActor))
        ->toThrow(UnauthorisedOrderAction::class);

    $confirmed = orderIn(OrderStatus::SellerConfirmed);

    expect(fn () => test()->machine->markReady($confirmed, OrderActor::forUser($this->buyer, $confirmed)))
        ->toThrow(UnauthorisedOrderAction::class);
});

it('will not let another shop act on an order', function (): void {
    $stranger = User::factory()->create();
    Seller::factory()->create(['user_id' => $stranger->getKey()]);

    $order = orderIn(OrderStatus::Paid);

    /* Not the owning seller, so they are not a Seller actor for this order. */
    expect(OrderActor::forUser($stranger, $order)->type)->toBe(OrderActorType::Buyer);

    expect(fn () => test()->machine->confirm($order, OrderActor::forUser($stranger, $order)))
        ->toThrow(UnauthorisedOrderAction::class);
});

it('lets the buyer complete a handed-over order', function (): void {
    $order = orderIn(OrderStatus::Delivered);

    $completed = $this->machine->complete($order, OrderActor::forUser($this->buyer, $order));

    expect($completed->status)->toBe(OrderStatus::Completed)
        ->and($completed->completed_at)->not->toBeNull()
        ->and($completed->auto_complete_at)->toBeNull();
});

it('marks ready as the order\'s own fulfilment method dictates', function (): void {
    $pickup = orderIn(OrderStatus::SellerConfirmed);

    expect($this->machine->markReady($pickup, staffActor($pickup))->status)
        ->toBe(OrderStatus::ReadyForPickup);

    $this->order->forceFill(['fulfilment_method' => FulfilmentMethod::Delivery])->save();
    $delivery = orderIn(OrderStatus::SellerConfirmed);

    expect($this->machine->markReady($delivery, staffActor($delivery))->status)
        ->toBe(OrderStatus::Dispatched);
});

it('starts the buyer\'s checking window from the order\'s own snapshot', function (): void {
    $order = orderIn(OrderStatus::ReadyForPickup);
    $order->forceFill(['auto_complete_window_days' => 5])->save();

    $collected = $this->machine->markHandedOver($order->refresh(), staffActor($order));

    expect($collected->auto_complete_at?->toDateString())
        ->toBe(now()->addDays(5)->toDateString());
});

it('writes an immutable timeline entry for every move', function (): void {
    $order = orderIn(OrderStatus::Paid);

    $this->machine->confirm($order, staffActor($order), 'Stock checked.');

    $event = OrderStatusEvent::query()->where('order_id', $order->getKey())->latest('id')->firstOrFail();

    expect($event->from_status)->toBe(OrderStatus::Paid)
        ->and($event->to_status)->toBe(OrderStatus::SellerConfirmed)
        ->and($event->reason)->toBe('Stock checked.');

    expect(fn () => $event->update(['reason' => 'Rewritten.']))
        ->toThrow(ImmutableRecordException::class);
});

it('records the platform rather than a person when nobody acted', function (): void {
    $order = orderIn(OrderStatus::PendingPayment);

    $this->machine->markPaid($order);

    $event = OrderStatusEvent::query()->where('order_id', $order->getKey())->latest('id')->firstOrFail();

    expect($event->actor_type)->toBe(OrderActorType::System)
        ->and($event->actor_id)->toBeNull()
        ->and($event->actorName())->toBe('Automatic');
});

it('announces every move and the three moves other modules act on', function (): void {
    Event::fake([OrderStateChanged::class, OrderPaid::class, OrderCompleted::class, OrderCancelled::class]);

    $order = orderIn(OrderStatus::PendingPayment);
    $this->machine->markPaid($order);

    Event::assertDispatched(OrderPaid::class);
    Event::assertDispatched(OrderStateChanged::class);

    $collected = orderIn(OrderStatus::Collected);
    $this->machine->complete($collected, OrderActor::forUser($this->buyer, $collected));

    Event::assertDispatched(OrderCompleted::class);
});

it('does not tell inventory to restock an order that was never paid', function (): void {
    Event::fake([OrderCancelled::class]);

    $order = orderIn(OrderStatus::PendingPayment);

    $this->machine->cancel($order, staffActor($order), 'Abandoned.');

    Event::assertNotDispatched(OrderCancelled::class);
});

it('tells inventory to restock a paid order that is cancelled or refunded', function (): void {
    Event::fake([OrderCancelled::class]);

    $paid = orderIn(OrderStatus::Paid);
    $this->machine->cancel($paid, staffActor($paid), 'Seller never confirmed.');

    Event::assertDispatched(OrderCancelled::class, 1);

    $disputed = orderIn(OrderStatus::Disputed);
    $this->machine->refund($disputed, staffActor($disputed), 'Found for the buyer.');

    Event::assertDispatched(OrderCancelled::class, 2);
});

it('freezes the commercial terms once and only once', function (): void {
    $order = orderIn(OrderStatus::PendingPayment);

    $this->machine->markPaid($order);
    $first = $order->fresh();

    expect($first->payment_mode)->not->toBeNull()
        ->and($first->monetisation_snapshot)->toHaveKey('commission_percent')
        ->and($first->confirm_due_at?->toDateTimeString())
        ->toBe($first->paid_at?->copy()->addHours(24)->toDateTimeString());

    /* A commission change afterwards must not reach an order already paid. */
    settings()->set('monetisation.commission_percent', '25.00');

    expect($order->fresh()->monetisation_snapshot['commission_percent'])
        ->toBe($first->monetisation_snapshot['commission_percent']);
});

/**
 * A staff actor for an order, used wherever a test needs legality tested
 * without authority getting in the way.
 */
function staffActor(Order $order): OrderActor
{
    /** @var User|null $staff */
    static $staff = null;

    if ($staff === null || $staff->fresh() === null) {
        SpatieRole::findOrCreate(Role::Moderator->value, 'web');
        $staff = User::factory()->create();
        $staff->assignRole(Role::Moderator->value);
    }

    return OrderActor::forUser($staff, $order);
}
