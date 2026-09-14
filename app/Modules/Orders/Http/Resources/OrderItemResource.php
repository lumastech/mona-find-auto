<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of an order, as it was described when it was bought.
 *
 * Everything here comes off the order item's own columns rather than the
 * listing behind it. That is the point of having copied them: a receipt page
 * opened a year later must say what the buyer bought, not what the seller
 * currently sells under the same id.
 *
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderItem $item */
        $item = $this->resource;

        return [
            'id' => $item->getKey(),
            'product_id' => $item->product_id,
            'variant_id' => $item->product_variant_id,
            'name' => $item->product_name,
            'variant_name' => $item->variant_name,
            'sku' => $item->sku,

            /* The two independent badges, both frozen at purchase. */
            'condition' => $item->condition?->value,
            'condition_label' => $item->condition?->label(),
            'inspection_status' => $item->inspection_status?->value,
            'inspection_label' => $item->inspection_status?->label(),

            'unit_price_ngwee' => $item->unit_price_ngwee->ngwee,
            'quantity' => $item->quantity,
            'total_ngwee' => $item->total_ngwee->ngwee,

            /* So a price nobody can find on a listing can be explained. */
            'was_quoted' => $item->wasQuoted(),
        ];
    }
}
