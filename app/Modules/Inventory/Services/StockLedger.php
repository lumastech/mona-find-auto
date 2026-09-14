<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Events\StockLevelChanged;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes a stock quantity.
 *
 * Two guarantees, and everything here exists to hold them.
 *
 * A shelf never goes negative and never oversells. Every change re-reads the
 * quantity under `SELECT ... FOR UPDATE` inside a transaction, so two people
 * paying for the last alternator at the same moment queue behind each other
 * rather than both reading "1 available" and both succeeding. The check and
 * the write happen against the same locked row; a read outside the lock, or
 * an `UPDATE ... SET quantity = quantity - 1` with the check done in PHP
 * beforehand, would both let one of them through.
 *
 * And nothing is applied twice. Order-driven movements carry the order id,
 * and the ledger has a unique index across (order, variant, reason) — so a
 * payment webhook that arrives twice, or a queued listener that is retried
 * after a timeout, finds the movement already recorded and changes nothing.
 * Idempotency lives in the database rather than in a flag somebody has to
 * remember to check.
 *
 * Events fire after the transaction commits, never inside it: a listener that
 * emailed a buyer about stock a rollback then restored would be worse than no
 * listener at all.
 */
class StockLedger
{
    /**
     * Take stock off a shelf.
     *
     * Throws rather than clamping when the shelf cannot cover it. This is the
     * reservation path — the caller is deciding whether a sale may happen at
     * all, and a silent clamp there is precisely an oversell.
     *
     * @throws InsufficientStock
     */
    public function decrement(
        ProductVariant $variant,
        int $quantity,
        StockMovementReason $reason,
        ?string $reference = null,
        ?int $orderId = null,
        ?User $actor = null,
        ?string $note = null,
    ): ?StockMovement {
        return $this->move($variant, -abs($quantity), $reason, $reference, $orderId, $actor, $note, clampToZero: false);
    }

    /**
     * Put stock back on a shelf.
     */
    public function increment(
        ProductVariant $variant,
        int $quantity,
        StockMovementReason $reason,
        ?string $reference = null,
        ?int $orderId = null,
        ?User $actor = null,
        ?string $note = null,
    ): ?StockMovement {
        return $this->move($variant, abs($quantity), $reason, $reference, $orderId, $actor, $note, clampToZero: false);
    }

    /**
     * Set a shelf to an absolute figure.
     *
     * What a seller typing a new number into the portal means, and what a
     * bulk upload row means. The movement records the difference, so the
     * history still reads as a series of changes even though the seller
     * thought in totals.
     */
    public function setQuantity(
        ProductVariant $variant,
        int $quantity,
        StockMovementReason $reason,
        ?User $actor = null,
        ?string $note = null,
    ): ?StockMovement {
        $quantity = max(0, $quantity);

        return $this->apply(
            $variant,
            static fn (int $current): int => $quantity,
            $reason,
            reference: null,
            orderId: null,
            actor: $actor,
            note: $note,
        );
    }

    /**
     * Take an order's lines off the shelves, after the money has arrived.
     *
     * Clamps at zero instead of throwing, and this is the one place that is
     * right. The buyer has paid; refusing to record the sale would leave the
     * platform claiming stock it has just sold. A shelf that could not cover
     * its line is set to zero, the shortfall is written onto the movement and
     * into the audit trail, and staff pick it up as an oversell to resolve —
     * which is a dispute, not a database problem.
     *
     * Lines are locked in variant-id order. Two orders sharing two variants
     * would otherwise be able to take each other's locks in opposite orders
     * and deadlock.
     *
     * @param  array<int, int>  $quantities  Keyed by variant id.
     * @return array<int, StockMovement>
     */
    public function applyOrderPaid(array $quantities, int $orderId, string $reference): array
    {
        return $this->applyOrderLines(
            $quantities,
            $orderId,
            $reference,
            StockMovementReason::OrderPaid,
            negative: true,
        );
    }

    /**
     * Put a cancelled order's lines back.
     *
     * Restores exactly what was taken rather than what the event says was
     * ordered: if the sale clamped at zero because the shelf was short, the
     * restore has to put back the smaller figure or the cancellation would
     * conjure stock the seller never had.
     *
     * @param  array<int, int>  $quantities  Keyed by variant id.
     * @return array<int, StockMovement>
     */
    public function applyOrderCancelled(array $quantities, int $orderId, string $reference, ?string $reason = null): array
    {
        $soldQuantities = StockMovement::query()
            ->forOrder($orderId)
            ->where('reason', StockMovementReason::OrderPaid)
            ->pluck('quantity_change', 'product_variant_id')
            ->map(static fn (int $change): int => abs($change))
            ->all();

        foreach ($quantities as $variantId => $quantity) {
            $quantities[$variantId] = $soldQuantities[$variantId] ?? $quantity;
        }

        return $this->applyOrderLines(
            $quantities,
            $orderId,
            $reference,
            StockMovementReason::OrderCancelled,
            negative: false,
            note: $reason,
        );
    }

    /**
     * @param  array<int, int>  $quantities  Keyed by variant id.
     * @return array<int, StockMovement>
     */
    private function applyOrderLines(
        array $quantities,
        int $orderId,
        string $reference,
        StockMovementReason $reason,
        bool $negative,
        ?string $note = null,
    ): array {
        /* Deterministic lock order across every order that touches these shelves. */
        ksort($quantities);

        $movements = [];

        foreach ($quantities as $variantId => $quantity) {
            $variant = ProductVariant::query()->find($variantId);

            if ($variant === null || $quantity <= 0) {
                continue;
            }

            $movement = $this->move(
                $variant,
                $negative ? -$quantity : $quantity,
                $reason,
                $reference,
                $orderId,
                actor: null,
                note: $note,
                clampToZero: true,
            );

            if ($movement !== null) {
                $movements[] = $movement;
            }
        }

        return $movements;
    }

    /**
     * Apply a signed change to one shelf.
     *
     * Returns null when there is nothing to do: an order movement already
     * recorded, or a change that works out to zero. A null is not a failure —
     * it is the idempotent path doing its job.
     */
    private function move(
        ProductVariant $variant,
        int $change,
        StockMovementReason $reason,
        ?string $reference,
        ?int $orderId,
        ?User $actor,
        ?string $note,
        bool $clampToZero,
    ): ?StockMovement {
        return $this->apply(
            $variant,
            static fn (int $current): int => $current + $change,
            $reason,
            $reference,
            $orderId,
            $actor,
            $note,
            $clampToZero,
            $change,
        );
    }

    /**
     * The one write path: lock, decide, persist, record, announce.
     *
     * @param  callable(int): int  $resolveQuantity  The new quantity, from the locked current one.
     * @param  int|null  $requested  The change that was asked for, so a clamp can be reported honestly.
     *
     * @throws InsufficientStock
     */
    private function apply(
        ProductVariant $variant,
        callable $resolveQuantity,
        StockMovementReason $reason,
        ?string $reference = null,
        ?int $orderId = null,
        ?User $actor = null,
        ?string $note = null,
        bool $clampToZero = false,
        ?int $requested = null,
    ): ?StockMovement {
        $movement = DB::transaction(function () use (
            $variant,
            $resolveQuantity,
            $reason,
            $reference,
            $orderId,
            $actor,
            $note,
            $clampToZero,
            $requested,
        ): ?StockMovement {
            /*
             * Re-read under a row lock. The $variant handed in may have been
             * loaded seconds ago and read for a page; what matters is what
             * the shelf holds now, with nobody else able to touch it until
             * this transaction ends.
             */
            $locked = ProductVariant::query()
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return null;
            }

            /* Already recorded: a retried job or a webhook that arrived twice. */
            if ($orderId !== null && $this->alreadyRecorded($orderId, $locked->getKey(), $reason)) {
                return null;
            }

            $before = $locked->quantity;
            $after = (int) $resolveQuantity($before);
            $shortfall = 0;

            if ($after < 0) {
                if (! $clampToZero) {
                    throw InsufficientStock::forVariant($locked, abs($requested ?? $after));
                }

                $shortfall = abs($after);
                $after = 0;
            }

            if ($after === $before && $orderId === null) {
                return null;
            }

            $locked->forceFill(['quantity' => $after])->save();

            $movement = StockMovement::query()->create([
                'product_variant_id' => $locked->getKey(),
                'product_id' => $locked->product_id,
                'seller_id' => $locked->product->seller_id,
                'quantity_change' => $after - $before,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => $reason,
                'reference' => $reference,
                'order_id' => $orderId,
                'note' => $this->noteFor($note, $shortfall),
                'actor_id' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            if ($shortfall > 0) {
                /*
                 * An oversell is a thing that happened to a real buyer, so it
                 * goes in the immutable trail rather than a log line.
                 */
                audit(
                    $actor,
                    'stock.oversold',
                    $locked,
                    ['quantity' => $before],
                    ['quantity' => $after],
                    sprintf('Order needed %d more than the shelf held.', $shortfall),
                    ['order_id' => $orderId, 'reference' => $reference, 'shortfall' => $shortfall],
                );
            }

            /* Hand the caller the post-write state rather than the stale one. */
            $variant->setRawAttributes($locked->getRawOriginal());
            $variant->syncOriginal();

            return $movement;
        });

        if ($movement !== null) {
            StockLevelChanged::dispatch(
                $variant,
                $movement->quantity_before,
                $movement->quantity_after,
                $movement,
            );
        }

        return $movement;
    }

    private function alreadyRecorded(int $orderId, int $variantId, StockMovementReason $reason): bool
    {
        return StockMovement::query()
            ->forOrder($orderId)
            ->where('product_variant_id', $variantId)
            ->where('reason', $reason)
            ->exists();
    }

    private function noteFor(?string $note, int $shortfall): ?string
    {
        if ($shortfall === 0) {
            return $note;
        }

        $shortfallNote = sprintf('Oversold by %d: the shelf could not cover this order.', $shortfall);

        return $note === null ? $shortfallNote : $note.' '.$shortfallNote;
    }
}
