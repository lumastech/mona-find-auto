<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment and the orders it covers.
 *
 * Sent back when a checkout is placed, so the client knows both what it owes
 * and what it just created. `public_id` rather than the row id, because that
 * is what the payment reference is built from and what the buyer will see.
 *
 * @mixin OrderGroup
 */
class OrderGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderGroup $group */
        $group = $this->resource;

        return [
            'id' => $group->public_id,
            'status' => $group->status->value,
            'status_label' => $group->status->label(),
            'payment_method' => $group->payment_method->value,
            'payment_method_label' => $group->payment_method->label(),

            'items_total_ngwee' => $group->items_total_ngwee->ngwee,
            'delivery_total_ngwee' => $group->delivery_total_ngwee->ngwee,
            'total_ngwee' => $group->total_ngwee->ngwee,

            'placed_at' => $group->placed_at?->toIso8601String(),
            'paid_at' => $group->paid_at?->toIso8601String(),

            'orders' => $this->whenLoaded(
                'orders',
                fn (): array => OrderResource::collection($group->orders)->resolve($request),
            ),
        ];
    }
}
