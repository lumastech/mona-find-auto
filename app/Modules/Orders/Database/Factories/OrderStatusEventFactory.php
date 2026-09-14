<?php

declare(strict_types=1);

namespace App\Modules\Orders\Database\Factories;

use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusEvent>
 */
class OrderStatusEventFactory extends Factory
{
    protected $model = OrderStatusEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'from_status' => OrderStatus::PendingPayment,
            'to_status' => OrderStatus::Paid,
            'actor_type' => OrderActorType::System,
            'created_at' => now(),
        ];
    }
}
