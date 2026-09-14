<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One buyable option's stock position.
 *
 * The seller gets the real number, because they are the one counting the
 * shelf. Buyers get StockLevel instead — see StockStatus on the storefront.
 * The price is here because the stock screen is also where a seller corrects
 * a price they got wrong, and it crosses the wire as integer ngwee like every
 * other amount.
 *
 * @mixin ProductVariant
 */
class StockVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $level = $this->stockLevel();

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'price_ngwee' => $this->price->ngwee,
            'quantity' => $this->quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
            /* What the seller would get if they left the threshold blank. */
            'effective_low_stock_threshold' => $this->lowStockThreshold(),
            'level' => [
                'value' => $level->value,
                'label' => $level->label(),
                'variant' => $level->badgeVariant(),
                'available' => $level->isAvailable(),
            ],
            'is_default' => $this->is_default,
        ];
    }
}
