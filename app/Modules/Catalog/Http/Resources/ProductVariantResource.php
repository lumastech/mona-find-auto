<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One buyable option.
 *
 * The price crosses the wire as an integer number of ngwee. Nothing here
 * formats or divides it — the browser does that, from the same integer.
 *
 * Stock crosses it twice: `quantity` for the seller's own screens, and
 * `level` for the storefront. They are not the same claim.
 *
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
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
            'in_stock' => $this->inStock(),
            /*
             * What a buyer is shown. The raw quantity above is for the
             * seller's own screens; the storefront renders this instead,
             * because a coarse "Low stock" is a claim the platform can stand
             * behind and "2 left" is not.
             */
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
