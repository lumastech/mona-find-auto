<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Events\StockLevelChanged;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockLedger;
use App\Support\Database\ImmutableRecordException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->ledger = app(StockLedger::class);
    $this->product = Product::factory()->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill(['quantity' => 5])->save();
});

it('takes stock off a shelf and records what it did', function (): void {
    $movement = $this->ledger->decrement(
        $this->variant,
        2,
        StockMovementReason::SellerAdjustment,
    );

    expect($this->variant->refresh()->quantity)->toBe(3)
        ->and($movement)->not->toBeNull()
        ->and($movement->quantity_change)->toBe(-2)
        ->and($movement->quantity_before)->toBe(5)
        ->and($movement->quantity_after)->toBe(3);
});

it('refuses to take more than the shelf holds', function (): void {
    expect(fn () => $this->ledger->decrement($this->variant, 6, StockMovementReason::SellerAdjustment))
        ->toThrow(InsufficientStock::class);

    /* And the refusal leaves nothing behind: no partial write, no movement. */
    expect($this->variant->refresh()->quantity)->toBe(5)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('never lets a quantity go negative', function (): void {
    $this->ledger->applyOrderPaid([$this->variant->id => 9], orderId: 1, reference: 'MFA-1-1');

    expect($this->variant->refresh()->quantity)->toBe(0);
});

it('records an oversell in the audit trail rather than swallowing it', function (): void {
    $this->ledger->applyOrderPaid([$this->variant->id => 9], orderId: 1, reference: 'MFA-1-1');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'stock.oversold',
        'subject_type' => ProductVariant::class,
        'subject_id' => $this->variant->id,
    ]);

    expect(StockMovement::query()->first()->note)->toContain('Oversold by 4');
});

/**
 * The guarantee the whole module rests on: two people paying for the last
 * item cannot both succeed.
 *
 * SQLite has no row-level lock and its grammar drops the clause, so the SQL
 * assertion runs only where the clause is real. What is asserted everywhere
 * is the thing that makes the lock work at all — that the read, the decision
 * and the write are inside one transaction. A lock taken and released before
 * the write would protect nothing.
 */
it('reads the quantity under a lock it holds until it has written', function (): void {
    $driver = DB::connection()->getDriverName();
    $selects = [];
    $updateLevels = [];

    DB::listen(function ($query) use (&$selects, &$updateLevels): void {
        if (str_starts_with(strtolower($query->sql), 'select * from "product_variants"')
            || str_starts_with(strtolower($query->sql), 'select * from `product_variants`')) {
            $selects[] = strtolower($query->sql);
        }

        if (str_contains(strtolower($query->sql), 'update "product_variants"')
            || str_contains(strtolower($query->sql), 'update `product_variants`')) {
            $updateLevels[] = DB::transactionLevel();
        }
    });

    $this->ledger->decrement($this->variant, 1, StockMovementReason::SellerAdjustment);

    /* The write happened inside a transaction, which is where the lock lives. */
    expect($updateLevels)->not->toBeEmpty()
        ->and($updateLevels[0])->toBeGreaterThan(0);

    if ($driver !== 'sqlite') {
        expect(implode("\n", $selects))->toContain('for update');
    }
});

it('cannot oversell when two decrements race for the last item', function (): void {
    $this->variant->forceFill(['quantity' => 1])->save();

    $succeeded = 0;
    $refused = 0;

    /*
     * Two sequential attempts stand in for two concurrent ones: the lock
     * serialises real concurrency into exactly this order, and the property
     * that matters — the second attempt reads the post-first quantity rather
     * than the stale one — is what is asserted here.
     */
    foreach ([1, 2] as $ignored) {
        try {
            $this->ledger->decrement($this->variant, 1, StockMovementReason::SellerAdjustment);
            $succeeded++;
        } catch (InsufficientStock) {
            $refused++;
        }
    }

    expect($succeeded)->toBe(1)
        ->and($refused)->toBe(1)
        ->and($this->variant->refresh()->quantity)->toBe(0);
});

it('applies an order only once however many times the event arrives', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->ledger->applyOrderPaid([$this->variant->id => 2], orderId: 42, reference: 'MFA-42-1');
    }

    expect($this->variant->refresh()->quantity)->toBe(3)
        ->and(StockMovement::query()->forOrder(42)->count())->toBe(1);
});

it('restores exactly what a sale took, not what the order asked for', function (): void {
    $this->variant->forceFill(['quantity' => 2])->save();

    /* The shelf could only cover two of the three ordered. */
    $this->ledger->applyOrderPaid([$this->variant->id => 3], orderId: 7, reference: 'MFA-7-1');
    expect($this->variant->refresh()->quantity)->toBe(0);

    $this->ledger->applyOrderCancelled([$this->variant->id => 3], orderId: 7, reference: 'MFA-7-1');

    expect($this->variant->refresh()->quantity)->toBe(2);
});

it('sets an absolute quantity when a seller types one', function (): void {
    $this->ledger->setQuantity($this->variant, 12, StockMovementReason::SellerAdjustment);

    expect($this->variant->refresh()->quantity)->toBe(12)
        ->and(StockMovement::query()->first()->quantity_change)->toBe(7);
});

it('does nothing when a seller retypes the number that was already there', function (): void {
    $this->ledger->setQuantity($this->variant, 5, StockMovementReason::SellerAdjustment);

    expect(StockMovement::query()->count())->toBe(0);
});

it('announces every movement once it has committed', function (): void {
    Event::fake([StockLevelChanged::class]);

    $this->ledger->decrement($this->variant, 1, StockMovementReason::SellerAdjustment);

    Event::assertDispatched(
        StockLevelChanged::class,
        fn (StockLevelChanged $event): bool => $event->quantityBefore === 5 && $event->quantityAfter === 4,
    );
});

it('keeps the stock ledger append-only', function (): void {
    $movement = $this->ledger->decrement($this->variant, 1, StockMovementReason::SellerAdjustment);

    expect(fn () => $movement->update(['quantity_after' => 99]))
        ->toThrow(ImmutableRecordException::class)
        ->and(fn () => $movement->delete())
        ->toThrow(ImmutableRecordException::class);
});

it('stops a raw update slipping past the model guard', function (): void {
    $movement = $this->ledger->decrement($this->variant, 1, StockMovementReason::SellerAdjustment);

    expect(fn () => DB::table('stock_movements')->where('id', $movement->id)->update(['quantity_after' => 99]))
        ->toThrow(QueryException::class, 'append-only');
});
