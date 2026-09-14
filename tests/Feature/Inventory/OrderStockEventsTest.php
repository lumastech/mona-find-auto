<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Support\OrderStockLine;
use Illuminate\Support\Facades\Notification;

/**
 * The contract between Orders and Inventory.
 *
 * Orders does not exist yet; these two events are the whole of what it will
 * say to this module, so they are exercised here from the day the listener is
 * written rather than discovered to disagree in Prompt 07.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->product = Product::factory()->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill(['quantity' => 10])->save();

    $this->second = Product::factory()->create();
    $this->secondVariant = $this->second->variants()->first();
    $this->secondVariant->forceFill(['quantity' => 4])->save();
});

it('takes stock off the shelf when an order is paid', function (): void {
    OrderPaid::dispatch(101, 'MFA-101-1', [
        new OrderStockLine($this->variant->id, 3),
        new OrderStockLine($this->secondVariant->id, 1),
    ]);

    expect($this->variant->refresh()->quantity)->toBe(7)
        ->and($this->secondVariant->refresh()->quantity)->toBe(3);
});

it('writes the payment reference onto every movement it makes', function (): void {
    OrderPaid::dispatch(101, 'MFA-101-1', [new OrderStockLine($this->variant->id, 3)]);

    $movement = StockMovement::query()->firstOrFail();

    expect($movement->reference)->toBe('MFA-101-1')
        ->and($movement->order_id)->toBe(101)
        ->and($movement->reason)->toBe(StockMovementReason::OrderPaid);
});

it('ignores a payment event that arrives a second time', function (): void {
    $event = new OrderPaid(101, 'MFA-101-1', [new OrderStockLine($this->variant->id, 3)]);

    OrderPaid::dispatch(...[$event->orderId, $event->reference, $event->lines]);
    OrderPaid::dispatch(...[$event->orderId, $event->reference, $event->lines]);

    expect($this->variant->refresh()->quantity)->toBe(7)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('puts stock back when the order is cancelled', function (): void {
    OrderPaid::dispatch(101, 'MFA-101-1', [new OrderStockLine($this->variant->id, 3)]);
    OrderCancelled::dispatch(101, 'MFA-101-1', [new OrderStockLine($this->variant->id, 3)], 'Buyer changed their mind.');

    expect($this->variant->refresh()->quantity)->toBe(10)
        ->and(StockMovement::query()->count())->toBe(2);
});

it('restores a cancelled order only once', function (): void {
    OrderPaid::dispatch(101, 'MFA-101-1', [new OrderStockLine($this->variant->id, 3)]);

    foreach (range(1, 3) as $ignored) {
        OrderCancelled::dispatch(101, 'MFA-101-1', [new OrderStockLine($this->variant->id, 3)]);
    }

    expect($this->variant->refresh()->quantity)->toBe(10);
});

it('adds up two lines that name the same option', function (): void {
    OrderPaid::dispatch(101, 'MFA-101-1', [
        new OrderStockLine($this->variant->id, 2),
        new OrderStockLine($this->variant->id, 3),
    ]);

    expect($this->variant->refresh()->quantity)->toBe(5)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('shrugs off a line naming an option that no longer exists', function (): void {
    OrderPaid::dispatch(101, 'MFA-101-1', [
        new OrderStockLine(999999, 1),
        new OrderStockLine($this->variant->id, 2),
    ]);

    expect($this->variant->refresh()->quantity)->toBe(8);
});
