<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderDispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A dispute as the moderator queue and the two parties see it.
 *
 * The order is embedded rather than linked because a moderator working a
 * queue needs the number, the money and the shop in front of them to triage
 * at all — a list of reasons with ids beside them is not a queue.
 *
 * @mixin OrderDispute
 */
class DisputeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderDispute $dispute */
        $dispute = $this->resource;
        $order = $dispute->order;

        return [
            'id' => $dispute->getKey(),
            'reason' => $dispute->reason->value,
            'reason_label' => $dispute->reason->label(),
            'covered_by_platform_minimum' => $dispute->reason->coveredByPlatformMinimum(),
            'details' => $dispute->details,

            'status' => $dispute->status->value,
            'status_label' => $dispute->status->label(),
            'status_variant' => $dispute->status->badgeVariant(),

            'resolution' => $dispute->resolution?->value,
            'resolution_label' => $dispute->resolution?->label(),
            'resolution_note' => $dispute->resolution_note,
            'refund_ngwee' => $dispute->refund_amount_ngwee->ngwee,
            'resolved_by' => $dispute->resolver?->name,
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'opened_at' => $dispute->created_at?->toIso8601String(),
            'opened_by' => $dispute->opener->name,

            'photos' => $dispute->getMedia('evidence')
                ->map(static fn (Media $media): array => [
                    'id' => $media->getKey(),
                    'url' => $media->getUrl(),
                    'name' => $media->file_name,
                ])
                ->all(),

            'order' => [
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'total_ngwee' => $order->total_ngwee->ngwee,
                'fulfilment_label' => $order->fulfilment_method->label(),
                'paid_at' => $order->paid_at?->toIso8601String(),
                'buyer' => $order->buyer->name,
                'seller' => $order->seller->business_name,
                'seller_slug' => $order->seller->slug,
            ],
        ];
    }
}
