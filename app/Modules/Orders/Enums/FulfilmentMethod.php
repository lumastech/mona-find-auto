<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * How one seller's part of an order reaches the buyer.
 *
 * Chosen per seller group at checkout rather than per cart, because a buyer
 * assembling a repair across town genuinely does collect the alternator
 * themselves and have the filter sent — and because each shop decides for
 * itself whether it delivers at all.
 *
 * The method also sets the clock. A collected part is in the buyer's hands
 * and gets a short window to be checked; a delivered one may sit at a gate
 * for days, so it gets a longer one. Both windows are admin-configurable.
 */
enum FulfilmentMethod: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Collect from seller',
            self::Delivery => 'Delivery',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pickup => 'Collect the part from the seller yourself. No delivery charge.',
            self::Delivery => 'The seller sends the part to your address.',
        };
    }

    /**
     * The state that means "the seller has done their part".
     */
    public function readyStatus(): OrderStatus
    {
        return match ($this) {
            self::Pickup => OrderStatus::ReadyForPickup,
            self::Delivery => OrderStatus::Dispatched,
        };
    }

    /**
     * The state that means "the buyer has it".
     */
    public function handoverStatus(): OrderStatus
    {
        return match ($this) {
            self::Pickup => OrderStatus::Collected,
            self::Delivery => OrderStatus::Delivered,
        };
    }

    /**
     * How long the buyer has to check the part before the platform completes
     * the order on their behalf and escrow releases.
     *
     * Read from settings on every call rather than cached on the enum: the
     * value an administrator sets today must not govern orders that are
     * already running, which is why the number is snapshotted onto the order
     * at payment time and read from there afterwards.
     */
    public function autoCompleteWindowDays(): int
    {
        return match ($this) {
            self::Pickup => (int) settings('escrow.pickup_window_days', 3),
            self::Delivery => (int) settings('escrow.delivery_window_days', 7),
        };
    }

    /**
     * Whether this method needs a delivery address on the order.
     */
    public function needsAddress(): bool
    {
        return $this === self::Delivery;
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $method): array => [
            'value' => $method->value,
            'label' => $method->label(),
            'description' => $method->description(),
        ], self::cases());
    }
}
