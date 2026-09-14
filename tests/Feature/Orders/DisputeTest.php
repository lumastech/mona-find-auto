<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Enums\DisputeReason;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Enums\DisputeStatus;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\DisputeOpened;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Exceptions\DisputeNotAllowed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\DisputeService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->machine = app(OrderStateMachine::class);
    $this->disputes = app(DisputeService::class);

    $this->buyer = User::factory()->create();
    $this->seller = Seller::factory()->create();

    SpatieRole::findOrCreate(Role::Moderator->value, 'web');
    $this->moderator = User::factory()->create();
    $this->moderator->assignRole(Role::Moderator->value);

    $product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $product->variants()->first();
    $this->variant->forceFill(['price' => 60_000, 'quantity' => 10])->save();
});

/**
 * An order in the buyer's hands, with money in escrow behind it.
 */
function handedOverOrder(): Order
{
    /*
     * The total is pinned rather than left to the factory's K50–K5,000
     * range. The partial-refund test asks for a fixed K150 back, and a
     * resolution is clamped to the order total — so a random total below K150
     * made that test fail roughly one run in four, on the clamp working
     * correctly.
     */
    $order = Order::factory()
        ->forBuyer(test()->buyer)
        ->forSeller(test()->seller)
        ->state([
            'fulfilment_method' => FulfilmentMethod::Delivery,
            'items_total_ngwee' => 300_00,
            'delivery_fee_ngwee' => 0,
            'total_ngwee' => 300_00,
        ])
        ->create();

    OrderItem::factory()->forVariant(test()->variant, 1)->create(['order_id' => $order->getKey()]);

    $order = test()->machine->markPaid($order->refresh());
    $sellerActor = OrderActor::forUser(test()->seller->user, $order);

    test()->machine->confirm($order, $sellerActor);
    test()->machine->markReady($order->refresh(), $sellerActor);

    return test()->machine->markHandedOver($order->refresh(), $sellerActor);
}

it('puts the order on hold the moment a dispute is opened', function (): void {
    $order = handedOverOrder();

    $dispute = $this->disputes->open(
        $order,
        $this->buyer,
        DisputeReason::Damaged,
        'The housing was cracked right through when I opened the box.',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Disputed)
        ->and($order->fresh()->auto_complete_at)->toBeNull()
        ->and($dispute->status)->toBe(DisputeStatus::Open);
});

it('keeps the buyer\'s photographs as part of the record', function (): void {
    Storage::fake('public');

    $order = handedOverOrder();

    $dispute = $this->disputes->open(
        $order,
        $this->buyer,
        DisputeReason::Damaged,
        'The housing was cracked right through when I opened the box.',
        [UploadedFile::fake()->image('cracked-housing.jpg')],
    );

    expect($dispute->getMedia('evidence'))->toHaveCount(1);
});

it('refuses a second dispute while one is still open', function (): void {
    $order = handedOverOrder();

    $this->disputes->open($order, $this->buyer, DisputeReason::WrongPart, 'This is for the wrong engine entirely.');

    expect(fn () => $this->disputes->open(
        $order->refresh(),
        $this->buyer,
        DisputeReason::Damaged,
        'And it is also broken, for what it is worth.',
    ))->toThrow(DisputeNotAllowed::class);
});

it('refuses a dispute on an order nobody paid for', function (): void {
    $order = Order::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();

    expect(fn () => $this->disputes->open($order, $this->buyer, DisputeReason::NotReceived, 'Nothing ever arrived here.'))
        ->toThrow(DisputeNotAllowed::class);
});

it('refuses a dispute once the order has completed', function (): void {
    $order = handedOverOrder();
    $this->machine->complete($order, OrderActor::forUser($this->buyer, $order));

    expect(fn () => $this->disputes->open(
        $order->refresh(),
        $this->buyer,
        DisputeReason::Damaged,
        'I noticed the crack a week after confirming.',
    ))->toThrow(DisputeNotAllowed::class);
});

it('releases to the seller and completes the order', function (): void {
    Event::fake([DisputeResolved::class]);

    $order = handedOverOrder();
    $dispute = $this->disputes->open($order, $this->buyer, DisputeReason::Other, 'I changed my mind about this part.');

    $this->disputes->resolve($dispute, DisputeResolution::Release, $this->moderator, null, 'Part matches the listing.');

    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->fresh()->refunded_amount_ngwee->ngwee)->toBe(0)
        ->and($dispute->fresh()->status)->toBe(DisputeStatus::Resolved);

    Event::assertDispatched(
        DisputeResolved::class,
        fn (DisputeResolved $event): bool => $event->resolution === DisputeResolution::Release
            && $event->refundAmount->isZero(),
    );
});

it('completes the order on a partial refund and records the amount', function (): void {
    Event::fake([DisputeResolved::class]);

    $order = handedOverOrder();
    $dispute = $this->disputes->open($order, $this->buyer, DisputeReason::NotAsDescribed, 'The finish is not what the photos showed.');

    $this->disputes->resolve(
        $dispute,
        DisputeResolution::PartialRefund,
        $this->moderator,
        Money::ofKwacha('150.00'),
        'Half back; the part is usable.',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->fresh()->refunded_amount_ngwee->ngwee)->toBe(15_000);

    Event::assertDispatched(
        DisputeResolved::class,
        fn (DisputeResolved $event): bool => $event->refundAmount->ngwee === 15_000,
    );
});

it('refunds in full and puts the stock back', function (): void {
    $order = handedOverOrder();

    expect($this->variant->fresh()->quantity)->toBe(9);

    $dispute = $this->disputes->open($order, $this->buyer, DisputeReason::Counterfeit, 'The box and the casting marks do not match.');

    $this->disputes->resolve($dispute, DisputeResolution::FullRefund, $this->moderator, null, 'Confirmed counterfeit.');

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->fresh()->refunded_amount_ngwee->ngwee)->toBe($order->total_ngwee->ngwee)
        ->and($this->variant->fresh()->quantity)->toBe(10);
});

it('will not refund more than the order was worth', function (): void {
    $order = handedOverOrder();
    $dispute = $this->disputes->open($order, $this->buyer, DisputeReason::Damaged, 'Cracked straight through the casing.');

    $this->disputes->resolve(
        $dispute,
        DisputeResolution::PartialRefund,
        $this->moderator,
        Money::ofKwacha('100000.00'),
        'Typed the wrong figure.',
    );

    expect($order->fresh()->refunded_amount_ngwee->ngwee)->toBe($order->total_ngwee->ngwee);
});

it('refuses to resolve the same dispute twice', function (): void {
    $order = handedOverOrder();
    $dispute = $this->disputes->open($order, $this->buyer, DisputeReason::Other, 'Something is not right with this order.');

    $this->disputes->resolve($dispute, DisputeResolution::Release, $this->moderator, null, 'Nothing wrong with it.');

    expect(fn () => $this->disputes->resolve($dispute->fresh(), DisputeResolution::FullRefund, $this->moderator, null, 'Changed my mind.'))
        ->toThrow(DisputeNotAllowed::class);
});

it('announces an opened dispute so the seller can be told', function (): void {
    Event::fake([DisputeOpened::class]);

    $order = handedOverOrder();
    $this->disputes->open($order, $this->buyer, DisputeReason::NotReceived, 'The courier marked it delivered but nothing came.');

    Event::assertDispatched(DisputeOpened::class);
});

it('counts a seller\'s dispute rate against orders that were actually paid', function (): void {
    $order = handedOverOrder();
    $this->disputes->open($order, $this->buyer, DisputeReason::Damaged, 'Cracked right through the housing.');

    /* One paid order, one disputed. */
    expect($this->disputes->disputeRateFor($this->seller->getKey(), 30))->toBe(100.0);

    /* An unpaid order does not flatter the figure. */
    Order::factory()->forSeller($this->seller)->create();

    expect($this->disputes->disputeRateFor($this->seller->getKey(), 30))->toBe(100.0);
});
