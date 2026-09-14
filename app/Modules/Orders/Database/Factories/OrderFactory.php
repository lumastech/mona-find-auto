<?php

declare(strict_types=1);

namespace App\Modules\Orders\Database\Factories;

use App\Models\User;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $items = $this->faker->numberBetween(5_000, 500_000);

        return [
            'order_group_id' => OrderGroup::factory(),
            'user_id' => User::factory(),
            'seller_id' => Seller::factory(),
            'status' => OrderStatus::PendingPayment,
            'fulfilment_method' => FulfilmentMethod::Pickup,
            'items_total_ngwee' => $items,
            'delivery_fee_ngwee' => 0,
            'total_ngwee' => $items,
        ];
    }

    /**
     * An order that has been paid for, with its terms already frozen.
     *
     * States after this one build on it, because everything interesting about
     * an order — the seller's clock, the escrow window, the dispute window —
     * only exists once the money has arrived.
     */
    public function paid(): static
    {
        return $this->state(function (array $attributes): array {
            $paidAt = now();
            $method = $attributes['fulfilment_method'] ?? FulfilmentMethod::Pickup;
            $method = $method instanceof FulfilmentMethod ? $method : FulfilmentMethod::from((string) $method);
            $confirmHours = (int) settings('orders.seller_confirm_window_hours', 24);

            return [
                'status' => OrderStatus::Paid,
                'paid_at' => $paidAt,
                'payment_mode' => PaymentMode::Escrow,
                'monetisation_snapshot' => MonetisationSnapshot::fromSettings()->toArray(),
                'auto_complete_window_days' => $method->autoCompleteWindowDays(),
                'seller_confirm_window_hours' => $confirmHours,
                'snapshot_at' => $paidAt,
                'confirm_due_at' => $paidAt->copy()->addHours($confirmHours),
            ];
        });
    }

    /**
     * Paid, but settled straight to the seller rather than held.
     *
     * The mode lives on the ORDER because it is snapshotted there at payment
     * time; a test that wants a direct-settlement order needs the snapshot to
     * say so, not the seller.
     */
    public function directSettlement(): static
    {
        return $this->paid()->state(['payment_mode' => PaymentMode::Direct]);
    }

    public function confirmed(): static
    {
        return $this->paid()->state([
            'status' => OrderStatus::SellerConfirmed,
            'confirmed_at' => now(),
            'confirm_due_at' => null,
        ]);
    }

    /**
     * Handed over, with the buyer's checking window running.
     */
    public function handedOver(): static
    {
        return $this->confirmed()->state(function (array $attributes): array {
            $method = $attributes['fulfilment_method'] ?? FulfilmentMethod::Pickup;
            $method = $method instanceof FulfilmentMethod ? $method : FulfilmentMethod::from((string) $method);
            $at = now();

            return [
                'status' => $method->handoverStatus(),
                'ready_at' => $at,
                'dispatched_at' => $method === FulfilmentMethod::Delivery ? $at : null,
                'handed_over_at' => $at,
                'auto_complete_at' => $at->copy()->addDays(
                    (int) ($attributes['auto_complete_window_days'] ?? $method->autoCompleteWindowDays()),
                ),
            ];
        });
    }

    public function completed(): static
    {
        return $this->handedOver()->state([
            'status' => OrderStatus::Completed,
            'completed_at' => now(),
            'auto_complete_at' => null,
        ]);
    }

    public function forDelivery(): static
    {
        return $this->state([
            'fulfilment_method' => FulfilmentMethod::Delivery,
            'delivery_address' => [
                'recipient_name' => $this->faker->name(),
                'recipient_phone' => '+260977000000',
                'street' => $this->faker->streetName(),
                'plot_number' => (string) $this->faker->buildingNumber(),
                'city' => 'Lusaka',
                'province' => 'Lusaka',
            ],
        ]);
    }

    public function forSeller(Seller $seller): static
    {
        return $this->state(['seller_id' => $seller->getKey()]);
    }

    public function forBuyer(User $user): static
    {
        return $this->state(['user_id' => $user->getKey()]);
    }
}
