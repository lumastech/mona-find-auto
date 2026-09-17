<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One seller's order, for the buyer's pages and the mobile app.
 *
 * The `can` block is the part worth explaining. Whether a buyer may confirm
 * receipt or raise a problem is decided by the same rules the state machine
 * enforces, so it is answered here rather than reconstructed in the UI from
 * a status string — a page that works out its own permissions is a page that
 * eventually offers a button the server will refuse.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            /*
             * The id as well as the number. The number is the route key and
             * what a buyer reads; the id is what a polymorphic subject —
             * a message thread, say — is addressed by.
             */
            'id' => $order->getKey(),
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'status_description' => $order->status->buyerDescription(),
            'status_variant' => $order->status->badgeVariant(),

            'fulfilment_method' => $order->fulfilment_method->value,
            'fulfilment_label' => $order->fulfilment_method->label(),

            'seller' => [
                'id' => $order->seller->getKey(),
                'slug' => $order->seller->slug,
                'business_name' => $order->seller->business_name,
                'verified' => $order->seller->isVerified(),
                'phone' => $order->seller->phone,
                'address' => $order->seller->singleLine(),
                'latitude' => $order->seller->latitude,
                'longitude' => $order->seller->longitude,
            ],

            'items' => $this->whenLoaded(
                'items',
                fn (): array => OrderItemResource::collection($order->items)->resolve($request),
            ),

            'items_total_ngwee' => $order->items_total_ngwee->ngwee,
            'delivery_fee_ngwee' => $order->delivery_fee_ngwee->ngwee,
            'total_ngwee' => $order->total_ngwee->ngwee,
            'refunded_ngwee' => $order->refunded_amount_ngwee->ngwee,

            'delivery_address' => $order->delivery_address,
            'delivery_instructions' => $order->delivery_instructions,

            'placed_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'auto_complete_at' => $order->auto_complete_at?->toIso8601String(),

            'timeline' => $this->whenLoaded('statusEvents', fn (): array => $order->statusEvents
                ->map(static fn (OrderStatusEvent $event): array => [
                    'status' => $event->to_status->value,
                    'headline' => $event->headline(),
                    'actor' => $event->actorName(),
                    'actor_type' => $event->actor_type->value,
                    'reason' => $event->reason,
                    'at' => $event->created_at->toIso8601String(),
                ])
                ->all()),

            'dispute' => $this->whenLoaded('disputes', fn (): ?array => $this->disputePayload($order)),

            /*
             * Answered by the same rules the server enforces, so the UI never
             * offers an action that would be refused.
             */
            'can' => [
                'confirm_receipt' => $order->awaitsBuyerConfirmation(),
                'open_dispute' => $order->allowsDispute(),
                'download_receipt' => $order->isPaid(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function disputePayload(Order $order): ?array
    {
        $dispute = $order->disputes->first();

        if ($dispute === null) {
            return null;
        }

        return [
            'id' => $dispute->getKey(),
            'reason' => $dispute->reason->value,
            'reason_label' => $dispute->reason->label(),
            'details' => $dispute->details,
            'status' => $dispute->status->value,
            'status_label' => $dispute->status->label(),
            'status_variant' => $dispute->status->badgeVariant(),
            'resolution' => $dispute->resolution?->value,
            'resolution_label' => $dispute->resolution?->label(),
            'refund_ngwee' => $dispute->refund_amount_ngwee->ngwee,
            'opened_at' => $dispute->created_at?->toIso8601String(),
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'photos' => $dispute->getMedia('evidence')
                ->map(static fn ($media): string => $media->getUrl())
                ->all(),
        ];
    }
}
